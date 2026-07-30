<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverChannelMessage;
use App\Jobs\DeliverWebhooks;
use App\Jobs\NotifySlack;
use App\Models\Attachment;
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
        private readonly ModerationService $moderation,
    ) {}

    /**
     * @param  list<string>  $attachmentIds  pre-validated (owned, unlinked, not quarantined)
     * @return array{message: Message, duplicate: bool}
     */
    public function send(
        Conversation $conversation,
        string $senderType,
        string $senderId,
        string $body,
        string $idempotencyKey,
        string $contentType = 'text',
        ?string $correlationId = null,
        array $attachmentIds = [],
        ?string $replyToMessageId = null,
        ?string $channelId = null,
    ): array {
        $existing = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            // Retry of an already-persisted send: the original message (and
            // its original attachment links) wins — new ids are ignored.
            return ['message' => $existing->load('attachments'), 'duplicate' => true];
        }

        // Moderation: mask configured profanity in visitor-authored text before
        // it is persisted, so the stored body, previews, outbox event and
        // webhook payload all carry the filtered text (agents/bots pass through).
        if ($senderType === 'visitor') {
            $body = $this->moderation->maskProfanity($body);
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
            'channel_id' => $channelId ?? $conversation->channel_id,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'sequence_number' => $sequence,
            'content_type' => $contentType,
            'body' => $body,
            'lifecycle_state' => 'persisted',
            'reply_to_message_id' => $replyToMessageId,
            'idempotency_key' => $idempotencyKey,
            'correlation_id' => $correlationId,
            'sent_at' => now(),
        ]);

        $attachmentCount = 0;
        if ($attachmentIds !== []) {
            // Link inside the same transaction as the message; the WHERE
            // clauses re-assert what the controller validated (unlinked,
            // same conversation, not quarantined) against races.
            $attachmentCount = Attachment::query()
                ->whereIn('id', $attachmentIds)
                ->where('conversation_id', $conversation->id)
                ->whereNull('message_id')
                ->where('scan_status', '!=', Attachment::STATUS_QUARANTINED)
                ->update(['message_id' => $message->id]);

            abort_if($attachmentCount !== count($attachmentIds), 422, 'One or more attachments are not linkable.');
        }

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
            'attachment_count' => $attachmentCount,
            'ai_involvement' => $senderType === 'chatbot' ? 'chatbot_reply' : 'none',
        ], $senderType.':'.$senderId, $correlationId);

        // Interim direct fan-out (used when NATS is not configured; the
        // gateway's JetStream consumer is the primary path, ADR-005).
        $this->broadcaster->messageAccepted($message);

        // Channel delivery (Phase 29): agent/bot replies in a channel-bound
        // conversation go back out via the provider (queued, never inline).
        if ($conversation->channel_id !== null && in_array($senderType, ['agent', 'chatbot'], true)) {
            DeliverChannelMessage::dispatch(
                (string) $conversation->organization_id,
                $message->id,
            )->afterCommit();
        }

        // Customer webhooks (Phase 35, §15): data-minimized like the outbox
        // payload — subscribers fetch the full body via the API if needed.
        DeliverWebhooks::dispatch(
            (string) $conversation->organization_id,
            'message.created',
            [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'sender_type' => $senderType,
                'sequence_number' => (int) $message->sequence_number,
                'content_type' => $contentType,
                'preview' => mb_substr($body, 0, 140),
            ],
        )->afterCommit();

        // The first message of a conversation marks it active — fire the
        // conversation.created webhook (customer webhooks, §15).
        if ((int) $message->sequence_number === 1) {
            DeliverWebhooks::dispatch(
                (string) $conversation->organization_id,
                'conversation.created',
                [
                    'conversation_id' => $conversation->id,
                    'channel_id' => $conversation->channel_id,
                    'contact_id' => $conversation->contact_id,
                    'visitor_id' => $conversation->visitor_id,
                    'first_sender_type' => $senderType,
                ],
            )->afterCommit();
        }

        // Slack integration: the first customer message opens a conversation —
        // notify the org's Slack channel (queued; no-op if not configured).
        if (in_array($senderType, ['visitor', 'contact'], true) && (int) $message->sequence_number === 1) {
            NotifySlack::dispatch(
                (string) $conversation->organization_id,
                $conversation->id,
                mb_substr($body, 0, 140),
            )->afterCommit();
        }

        return ['message' => $message->load('attachments'), 'duplicate' => false];
    }
}
