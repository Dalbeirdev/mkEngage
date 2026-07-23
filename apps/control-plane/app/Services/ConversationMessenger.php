<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Single implementation of the message-send mechanics for every REST sender
 * (visitor widget, agent dashboard, later chatbot/system):
 *
 *  - duplicate idempotency key ⇒ the ORIGINAL message (RULES-message-ordering #8)
 *  - sequence assigned by atomic per-conversation increment under row lock
 *    inside the caller's transaction (RULES #1)
 *  - persist-before-confirm (§27): the returned model is already committed
 *    by the surrounding request transaction before any response leaves
 *
 * The Phoenix gateway (ADR-002) implements these same semantics in Elixir on
 * the WebSocket hot path; contract tests in tests/contract keep the two
 * implementations aligned.
 */
final class ConversationMessenger
{
    public function __construct(
        private readonly GatewayBroadcaster $broadcaster,
        private readonly EventPublisher $events,
    ) {}

    /** @return array{message: Message, duplicate: bool} */
    public function send(
        Conversation $conversation,
        string $senderType,
        string $senderId,
        string $body,
        string $idempotencyKey,
        string $contentType = 'text',
        ?string $correlationId = null,
    ): array {
        $existing = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return ['message' => $existing, 'duplicate' => true];
        }

        DB::table('conversations')
            ->where('id', $conversation->id)
            ->increment('last_sequence');

        /** @var int $sequence */
        $sequence = DB::table('conversations')
            ->where('id', $conversation->id)
            ->value('last_sequence');

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'sequence_number' => $sequence,
            'content_type' => $contentType,
            'body' => $body,
            'lifecycle_state' => 'persisted',
            'idempotency_key' => $idempotencyKey,
            'correlation_id' => $correlationId,
            'sent_at' => now(),
        ]);

        // Transactional outbox (ADR-005): the event commits WITH the message.
        // Data-minimized payload per contracts/events/conv.message.accepted —
        // consumers needing the body read it from PostgreSQL under their org
        // context.
        $this->events->record('conv.message.accepted.v1', (string) $conversation->organization_id, [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'channel_id' => $message->channel_id,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'sequence_number' => (int) $message->sequence_number,
            'persisted_at' => (string) $message->sent_at?->toIso8601String(),
            'content_type' => $contentType,
            'content_preview' => mb_substr($body, 0, 140),
            'attachment_count' => 0,
            'ai_involvement' => $senderType === 'chatbot' ? 'chatbot_reply' : 'none',
        ], $senderType.':'.$senderId, $correlationId);

        // Interim direct fan-out (used when NATS is not configured; the
        // gateway's JetStream consumer is the primary path, ADR-005).
        $this->broadcaster->messageAccepted($message);

        return ['message' => $message, 'duplicate' => false];
    }
}
