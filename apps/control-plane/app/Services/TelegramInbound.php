<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateChatbotReply;
use App\Models\Channel;
use App\Models\Chatbot;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use Ramsey\Uuid\Uuid;

/**
 * Telegram Bot API inbound processing (Phase 31). Mirrors WhatsAppInbound:
 * runs inside tenant context, text updates only in v1, provider ids hashed
 * into idempotency keys so Telegram's redeliveries never duplicate.
 *
 * Identity: contacts keyed by external_id "tg:{user_id}" (Telegram doesn't
 * expose phone numbers to bots).
 */
final class TelegramInbound
{
    public function __construct(
        private readonly ConversationMessenger $messenger,
        private readonly AssignmentService $assignments,
    ) {}

    /** @param array<string, mixed> $update  a Bot API Update object */
    public function process(Channel $channel, array $update): bool
    {
        $message = is_array($update['message'] ?? null) ? $update['message'] : null;
        if ($message === null) {
            return false; // edited_message/callbacks etc. — v2 territory.
        }

        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $userId = $from['id'] ?? null;
        $chatId = $chat['id'] ?? null;
        $messageId = $message['message_id'] ?? null;

        if (! is_numeric($userId) || ! is_numeric($chatId) || ! is_numeric($messageId)) {
            return false;
        }

        $text = is_string($message['text'] ?? null)
            ? $message['text']
            : '[unsupported message type]';

        $first = is_string($from['first_name'] ?? null) ? $from['first_name'] : '';
        $last = is_string($from['last_name'] ?? null) ? $from['last_name'] : '';
        $username = is_string($from['username'] ?? null) ? $from['username'] : null;
        $name = trim($first.' '.$last);
        if ($name === '') {
            $name = $username ?? ('Telegram '.$userId);
        }

        $contact = Contact::query()->firstOrCreate(
            ['external_id' => 'tg:'.$userId],
            ['name' => $name],
        );

        $threadId = (string) $chatId;
        $conversation = Conversation::query()
            ->where('channel_id', $channel->id)
            ->where('external_thread_id', $threadId)
            ->where('status', '!=', 'closed')
            ->latest('created_at')
            ->first();

        if ($conversation === null) {
            $conversation = Conversation::query()->create([
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'external_thread_id' => $threadId,
                'chatbot_id' => Chatbot::query()->where('status', 'active')->first()?->id,
                'department_id' => Department::query()->where('is_default', true)->first()?->id,
            ]);
            $this->assignments->autoAssign($conversation);
        }

        $result = $this->messenger->send(
            conversation: $conversation,
            senderType: 'contact',
            senderId: $contact->id,
            body: $text,
            idempotencyKey: Uuid::uuid5(Uuid::NAMESPACE_OID, "tg:{$threadId}:{$messageId}")->toString(),
            channelId: $channel->id,
        );

        if (! $result['duplicate'] && $conversation->chatbot_id !== null) {
            GenerateChatbotReply::dispatch(
                (string) $conversation->organization_id,
                $conversation->id,
            )->afterCommit();
        }

        return ! $result['duplicate'];
    }
}
