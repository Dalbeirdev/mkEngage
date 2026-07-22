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

        return ['message' => $message, 'duplicate' => false];
    }
}
