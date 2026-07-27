<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ConversationMessenger;
use App\Services\FlowRunner;
use App\Services\KnowledgeRetriever;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Chatbot auto-reply (§2: AI never runs in synchronous requests — Laravel
 * dispatches to the AI service from a queued job; ADR-003).
 *
 * Failure policy (RULES-failure-retry): AI unavailability must never break
 * chat — the job swallows ALL provider/transport failures (logged, never
 * rethrown), because under a sync queue an escaping exception would fail the
 * visitor's own send request. The next visitor message naturally retries;
 * durable retry orchestration moves to Temporal per ADR-004.
 */
final class GenerateChatbotReply implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $organizationId,
        private readonly string $conversationId,
    ) {}

    public function handle(
        Tenancy $tenancy,
        ConversationMessenger $messenger,
        KnowledgeRetriever $retriever,
        FlowRunner $flows,
    ): void {
        $tenancy->run($this->organizationId, function () use ($messenger, $retriever, $flows): void {
            $conversation = Conversation::query()->find($this->conversationId);

            if ($conversation === null || $conversation->status === 'closed') {
                return;
            }

            $chatbot = $conversation->chatbot_id !== null
                ? Chatbot::query()->whereKey($conversation->chatbot_id)->where('status', 'active')->first()
                : null;

            if ($chatbot === null) {
                return;
            }

            // Human takeover: once an agent has participated, the bot stays out.
            $agentSpoke = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('sender_type', 'agent')
                ->exists();

            if ($agentSpoke) {
                return;
            }

            $history = Message::query()
                ->where('conversation_id', $conversation->id)
                ->orderByDesc('sequence_number')
                ->limit(20)
                ->get()
                ->reverse()
                ->map(fn (Message $message): array => [
                    'sender_type' => $message->sender_type,
                    'body' => $message->body,
                ])
                ->values()
                ->all();

            if ($history === [] || $history[count($history) - 1]['sender_type'] === 'chatbot') {
                return; // Nothing new to answer.
            }

            // mkEngage Flow (Phase 27): a designed flow takes precedence over
            // free-form AI. FlowRunner returns false to delegate the turn to
            // the AI pipeline ("ai" node reached or flow finished).
            if (FlowRunner::hasFlow($chatbot)) {
                $lastEntry = $history[count($history) - 1];
                $flowHandled = $flows->step(
                    $conversation,
                    $chatbot,
                    $lastEntry['sender_type'] === 'visitor' ? $lastEntry['body'] : '',
                );

                if ($flowHandled) {
                    return;
                }
            }

            // RAG (§10/ADR-003): ground the reply in org knowledge when any
            // matches the visitor's last message. Empty on SQLite/no-knowledge.
            $lastVisitor = '';
            foreach (array_reverse($history) as $entry) {
                if ($entry['sender_type'] === 'visitor') {
                    $lastVisitor = $entry['body'];
                    break;
                }
            }
            $contextChunks = $lastVisitor === '' ? [] : $retriever->retrieve($lastVisitor);

            $token = config('services.ai.token');
            $timeout = config('services.ai.timeout', 25);
            $url = config('services.ai.url');

            try {
                $response = Http::withToken(is_string($token) ? $token : '')
                    ->timeout(is_numeric($timeout) ? (int) $timeout : 25)
                    ->acceptJson()
                    ->post(rtrim(is_string($url) ? $url : '', '/').'/v1/reply', [
                        'organization_id' => $this->organizationId,
                        'conversation_id' => $conversation->id,
                        'chatbot_name' => $chatbot->name,
                        'system_prompt' => $chatbot->system_prompt
                            ?? 'You are a helpful customer support assistant.',
                        'history' => $history,
                        'context_chunks' => $contextChunks,
                        'config' => [
                            'provider' => $chatbot->provider,
                            'model' => $chatbot->model,
                        ],
                    ]);
            } catch (\Throwable $error) {
                Log::warning('chatbot_reply_failed', [
                    'organization_id' => $this->organizationId,
                    'conversation_id' => $conversation->id,
                    'reason' => 'transport',
                ]);

                return;
            }

            if ($response->failed()) {
                Log::warning('chatbot_reply_failed', [
                    'organization_id' => $this->organizationId,
                    'conversation_id' => $conversation->id,
                    'status' => $response->status(),
                ]);

                return;
            }

            /** @var string $body */
            $body = $response->json('body');

            $messenger->send(
                conversation: $conversation,
                senderType: 'chatbot',
                senderId: $chatbot->id,
                body: $body,
                idempotencyKey: (string) Str::uuid7(),
            );
        });
    }
}
