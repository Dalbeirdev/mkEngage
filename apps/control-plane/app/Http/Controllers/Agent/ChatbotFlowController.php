<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Chatbot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * mkEngage Flow definition API (Phase 27). The graph is validated
 * structurally here so FlowRunner only ever executes well-formed flows:
 * unique node ids, allowlisted types, per-type required fields, and edges
 * that reference existing nodes.
 */
final class ChatbotFlowController extends Controller
{
    private const NODE_TYPES = ['message', 'options', 'question', 'handoff', 'ai', 'end'];

    public function show(string $chatbotId): JsonResponse
    {
        $chatbot = Chatbot::query()->find($chatbotId);
        abort_if($chatbot === null, 404);

        return response()->json(['flow' => $chatbot->flow]);
    }

    public function update(Request $request, string $chatbotId): JsonResponse
    {
        $chatbot = Chatbot::query()->find($chatbotId);
        abort_if($chatbot === null, 404);

        $validated = $request->validate([
            'flow' => ['nullable', 'array:start,nodes,edges'],
            'flow.start' => ['required_with:flow', 'string', 'max:40'],
            'flow.nodes' => ['required_with:flow', 'array', 'min:1', 'max:100'],
            'flow.nodes.*' => ['array:id,type,text,options,variable,x,y'],
            'flow.nodes.*.id' => ['required', 'string', 'max:40'],
            'flow.nodes.*.type' => ['required', 'in:'.implode(',', self::NODE_TYPES)],
            'flow.nodes.*.text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'flow.nodes.*.options' => ['sometimes', 'array', 'min:1', 'max:8'],
            'flow.nodes.*.options.*' => ['string', 'max:60'],
            'flow.nodes.*.variable' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            // Canvas positions (builder-only; the engine ignores them).
            'flow.nodes.*.x' => ['sometimes', 'integer', 'min:-100000', 'max:100000'],
            'flow.nodes.*.y' => ['sometimes', 'integer', 'min:-100000', 'max:100000'],
            'flow.edges' => ['sometimes', 'array', 'max:200'],
            'flow.edges.*' => ['array:from,to,option'],
            'flow.edges.*.from' => ['required', 'string', 'max:40'],
            'flow.edges.*.to' => ['required', 'string', 'max:40'],
            'flow.edges.*.option' => ['sometimes', 'string', 'max:60'],
        ]);

        $flow = $validated['flow'] ?? null;

        if (is_array($flow)) {
            $this->assertGraphIntegrity($flow);
        }

        $chatbot->flow = $flow;
        $chatbot->save();

        return response()->json(['flow' => $chatbot->flow]);
    }

    /** @param array<mixed> $flow */
    private function assertGraphIntegrity(array $flow): void
    {
        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = $flow['nodes'];
        $ids = array_values(array_filter(
            array_map(fn (array $node): mixed => $node['id'] ?? null, $nodes),
            is_string(...),
        ));

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['flow.nodes' => 'Node ids must be unique.']);
        }

        if (! in_array($flow['start'], $ids, true)) {
            throw ValidationException::withMessages(['flow.start' => 'Start must reference an existing node.']);
        }

        foreach ($nodes as $index => $node) {
            $type = is_string($node['type'] ?? null) ? $node['type'] : '';
            $text = $node['text'] ?? null;
            if (in_array($type, ['message', 'options', 'question'], true)
                && (! is_string($text) || trim($text) === '')) {
                throw ValidationException::withMessages([
                    "flow.nodes.{$index}.text" => 'This node type requires text.',
                ]);
            }
            if ($type === 'options' && ! is_array($node['options'] ?? null)) {
                throw ValidationException::withMessages([
                    "flow.nodes.{$index}.options" => 'Options nodes need at least one option.',
                ]);
            }
            if ($type === 'question' && ! is_string($node['variable'] ?? null)) {
                throw ValidationException::withMessages([
                    "flow.nodes.{$index}.variable" => 'Question nodes need a variable name.',
                ]);
            }
        }

        /** @var array<int, array<string, mixed>> $edges */
        $edges = is_array($flow['edges'] ?? null) ? $flow['edges'] : [];
        foreach ($edges as $index => $edge) {
            if (! in_array($edge['from'], $ids, true) || ! in_array($edge['to'], $ids, true)) {
                throw ValidationException::withMessages([
                    "flow.edges.{$index}" => 'Edges must reference existing nodes.',
                ]);
            }
        }
    }
}
