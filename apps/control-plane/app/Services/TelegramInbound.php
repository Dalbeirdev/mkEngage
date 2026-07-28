<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateChatbotReply;
use App\Models\Channel;
use App\Models\Chatbot;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
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
        private readonly ReactionToggler $reactions,
    ) {}

    /** @param array<string, mixed> $update  a Bot API Update object */
    public function process(Channel $channel, array $update): bool
    {
        // Phase 38: a customer emoji reaction inside Telegram arrives as a
        // message_reaction update (only if allowed_updates opted us in).
        $reaction = is_array($update['message_reaction'] ?? null) ? $update['message_reaction'] : null;
        if ($reaction !== null) {
            return $this->processReaction($channel, $reaction);
        }

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

    /**
     * Map a Telegram message_reaction update onto the message we sent and
     * record the customer's reaction (Phase 38). We only track reactions on
     * messages we delivered — those carry a provider_message_id to match on.
     *
     * @param  array<array-key, mixed>  $reaction  a MessageReactionUpdated object
     */
    private function processReaction(Channel $channel, array $reaction): bool
    {
        $chat = is_array($reaction['chat'] ?? null) ? $reaction['chat'] : [];
        $user = is_array($reaction['user'] ?? null) ? $reaction['user'] : [];
        $chatId = $chat['id'] ?? null;
        $messageId = $reaction['message_id'] ?? null;
        $userId = $user['id'] ?? null;

        // Anonymous (actor_chat) reactions carry no user id — nothing to
        // attribute, so skip. Non-numeric ids are malformed.
        if (! is_numeric($chatId) || ! is_numeric($messageId) || ! is_numeric($userId)) {
            return false;
        }

        $conversation = Conversation::query()
            ->where('channel_id', $channel->id)
            ->where('external_thread_id', (string) $chatId)
            ->latest('created_at')
            ->first();
        if ($conversation === null) {
            return false;
        }

        $message = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('provider_message_id', (string) $messageId)
            ->first();
        if ($message === null) {
            return false; // a reaction on a message we didn't send/track
        }

        $contact = Contact::query()->firstOrCreate(
            ['external_id' => 'tg:'.$userId],
            ['name' => 'Telegram '.$userId],
        );

        // new_reaction is the resulting set; take the first emoji reaction,
        // or null when the customer cleared it. (Custom/paid reactions have
        // no unicode emoji and are ignored in v1.)
        $newReaction = is_array($reaction['new_reaction'] ?? null) ? $reaction['new_reaction'] : [];
        $emoji = null;
        foreach ($newReaction as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'emoji' && is_string($entry['emoji'] ?? null)) {
                $emoji = $entry['emoji'];
                break;
            }
        }

        $this->reactions->set($message, 'contact', $contact->id, $emoji);

        return true;
    }
}
