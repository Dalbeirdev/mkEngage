<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Chatbot;
use App\Models\Conversation;
use Illuminate\Support\Str;

/**
 * mkEngage Flow v1 (Phase 27): executes a chatbot's visual flow graph.
 *
 * Definition (chatbots.flow):
 *   { "start": "n1",
 *     "nodes": [{"id","type","text"?,"options"?,"variable"?}, ...],
 *     "edges": [{"from","to","option"?}, ...] }
 *
 * Node types: message | options | question | handoff | ai | end.
 * Per-conversation state (conversations.flow_state):
 *   { "node": id|null, "awaiting": null|"options"|"input",
 *     "variables": {...}, "mode": "flow"|"ai"|"done" }
 *
 * step() returns TRUE when the flow handled the turn; FALSE hands the turn
 * to the AI pipeline (ai node reached, flow finished, or no valid flow).
 * The engine is deliberately deterministic and loop-guarded — a broken
 * graph degrades to the AI/no-reply path, never an infinite loop.
 */
final class FlowRunner
{
    private const MAX_STEPS = 20;

    public function __construct(
        private readonly ConversationMessenger $messenger,
        private readonly AssignmentService $assignments,
    ) {}

    public static function hasFlow(Chatbot $chatbot): bool
    {
        $flow = $chatbot->flow;

        return is_array($flow) && is_array($flow['nodes'] ?? null) && $flow['nodes'] !== []
            && is_string($flow['start'] ?? null);
    }

    public function step(Conversation $conversation, Chatbot $chatbot, string $visitorMessage): bool
    {
        if (! self::hasFlow($chatbot)) {
            return false;
        }

        /** @var array{start: string, nodes: array<int, array<string, mixed>>, edges?: array<int, array<string, mixed>>} $flow */
        $flow = $chatbot->flow;

        $state = is_array($conversation->flow_state) ? $conversation->flow_state : [
            'node' => $flow['start'],
            'awaiting' => null,
            'variables' => [],
            'mode' => 'flow',
        ];

        if (($state['mode'] ?? 'flow') !== 'flow') {
            return false; // Handed to AI or finished: flow stays out.
        }

        $nodes = collect($flow['nodes'])->keyBy(
            fn (array $node): string => is_string($node['id'] ?? null) ? $node['id'] : Str::uuid7()->toString(),
        );
        $edges = collect(is_array($flow['edges'] ?? null) ? $flow['edges'] : []);

        // Resolve an awaited visitor reply first.
        if (($state['awaiting'] ?? null) === 'options') {
            $match = $edges->first(
                fn (array $edge): bool => ($edge['from'] ?? null) === $state['node']
                    && is_string($edge['option'] ?? null)
                    && Str::lower(trim($edge['option'])) === Str::lower(trim($visitorMessage)),
            );

            if ($match === null) {
                // Unrecognized answer: re-prompt the same options node.
                $node = $nodes->get(is_string($state['node']) ? $state['node'] : '');
                if (is_array($node)) {
                    $this->sendOptions($conversation, $chatbot, $node, $state);
                }
                $this->persist($conversation, $state);

                return true;
            }

            $state['node'] = is_string($match['to'] ?? null) ? $match['to'] : null;
            $state['awaiting'] = null;
        } elseif (($state['awaiting'] ?? null) === 'input') {
            $node = $nodes->get(is_string($state['node']) ? $state['node'] : '');
            $variable = is_array($node) && is_string($node['variable'] ?? null) ? $node['variable'] : null;
            if ($variable !== null) {
                $variables = is_array($state['variables'] ?? null) ? $state['variables'] : [];
                $variables[$variable] = trim($visitorMessage);
                $state['variables'] = $variables;
            }
            $state['node'] = $this->defaultEdgeTarget($edges->all(), $state['node']);
            $state['awaiting'] = null;
        }

        // Advance until the flow needs the visitor again (or ends).
        for ($guard = 0; $guard < self::MAX_STEPS; $guard++) {
            $nodeId = $state['node'];
            $node = is_string($nodeId) ? $nodes->get($nodeId) : null;

            if (! is_array($node)) {
                $state['mode'] = 'done';
                break;
            }

            $type = is_string($node['type'] ?? null) ? $node['type'] : 'end';

            if ($type === 'message') {
                $this->sendText($conversation, $chatbot, $node, $state);
                $state['node'] = $this->defaultEdgeTarget($edges->all(), $nodeId);

                continue;
            }

            if ($type === 'options') {
                $this->sendOptions($conversation, $chatbot, $node, $state);
                $state['awaiting'] = 'options';
                break;
            }

            if ($type === 'question') {
                $this->sendText($conversation, $chatbot, $node, $state);
                $state['awaiting'] = 'input';
                break;
            }

            if ($type === 'handoff') {
                $this->sendText($conversation, $chatbot, $node, $state);
                $this->assignments->autoAssign($conversation);
                $state['mode'] = 'done';
                break;
            }

            if ($type === 'ai') {
                // Hybrid: the AI pipeline takes over from here on.
                $state['mode'] = 'ai';
                break;
            }

            // end (or unknown type — fail closed to a clean finish)
            $this->sendText($conversation, $chatbot, $node, $state);
            $state['mode'] = 'done';
            break;
        }

        if ($guard >= self::MAX_STEPS) {
            $state['mode'] = 'done'; // Loop guard: broken graphs finish quietly.
        }

        $this->persist($conversation, $state);

        // "ai" hands THIS turn to the AI pipeline too.
        return ($state['mode'] ?? 'flow') !== 'ai';
    }

    /** @param array<int, array<string, mixed>> $edges */
    private function defaultEdgeTarget(array $edges, mixed $from): ?string
    {
        foreach ($edges as $edge) {
            if (($edge['from'] ?? null) === $from && ! isset($edge['option'])) {
                return is_string($edge['to'] ?? null) ? $edge['to'] : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $state
     */
    private function sendText(Conversation $conversation, Chatbot $chatbot, array $node, array $state): void
    {
        $text = is_string($node['text'] ?? null) ? trim($node['text']) : '';
        if ($text === '') {
            return;
        }

        $this->messenger->send(
            conversation: $conversation,
            senderType: 'chatbot',
            senderId: $chatbot->id,
            body: $this->interpolate($text, $state),
            idempotencyKey: Str::uuid7()->toString(),
        );
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $state
     */
    private function sendOptions(Conversation $conversation, Chatbot $chatbot, array $node, array $state): void
    {
        $text = is_string($node['text'] ?? null) ? $this->interpolate(trim($node['text']), $state) : '';
        $options = is_array($node['options'] ?? null)
            ? array_values(array_filter($node['options'], is_string(...)))
            : [];

        $body = json_encode(['text' => $text, 'options' => $options]);

        $this->messenger->send(
            conversation: $conversation,
            senderType: 'chatbot',
            senderId: $chatbot->id,
            body: $body === false ? $text : $body,
            idempotencyKey: Str::uuid7()->toString(),
            contentType: 'rich',
        );
    }

    /** @param array<string, mixed> $state */
    private function interpolate(string $text, array $state): string
    {
        $variables = is_array($state['variables'] ?? null) ? $state['variables'] : [];
        foreach ($variables as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $text = str_replace('{{'.$key.'}}', (string) $value, $text);
            }
        }

        return $text;
    }

    /** @param array<string, mixed> $state */
    private function persist(Conversation $conversation, array $state): void
    {
        $conversation->flow_state = $state;
        $conversation->save();
    }
}
