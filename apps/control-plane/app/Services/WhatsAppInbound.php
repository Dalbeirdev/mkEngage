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
 * WhatsApp Cloud API inbound processing (Phase 29). Runs INSIDE tenant
 * context (the webhook controller establishes it). Text messages only in
 * v1 — other types persist a placeholder so agents see something happened.
 *
 * Idempotency: the provider message id (wamid) is deterministic-hashed to
 * a UUID and used as the messenger idempotency key, so Meta's retries
 * never duplicate messages.
 */
final class WhatsAppInbound
{
    public function __construct(
        private readonly ConversationMessenger $messenger,
        private readonly AssignmentService $assignments,
    ) {}

    /** @param array<string, mixed> $payload  the decoded webhook body */
    public function process(Channel $channel, array $payload): int
    {
        $handled = 0;

        foreach ($this->extractMessages($payload) as $entry) {
            $contact = Contact::query()->firstOrCreate(
                ['phone' => $entry['from']],
                ['name' => $entry['name'] ?? ('WhatsApp '.$entry['from'])],
            );

            $conversation = Conversation::query()
                ->where('channel_id', $channel->id)
                ->where('external_thread_id', $entry['from'])
                ->where('status', '!=', 'closed')
                ->latest('created_at')
                ->first();

            if ($conversation === null) {
                $conversation = Conversation::query()->create([
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'external_thread_id' => $entry['from'],
                    'chatbot_id' => Chatbot::query()->where('status', 'active')->first()?->id,
                    'department_id' => Department::query()->where('is_default', true)->first()?->id,
                ]);
                $this->assignments->autoAssign($conversation);
            }

            $result = $this->messenger->send(
                conversation: $conversation,
                senderType: 'contact',
                senderId: $contact->id,
                body: $entry['body'],
                idempotencyKey: Uuid::uuid5(Uuid::NAMESPACE_OID, $entry['wamid'])->toString(),
                channelId: $channel->id,
            );

            if (! $result['duplicate']) {
                $handled++;
                if ($conversation->chatbot_id !== null) {
                    // Same bot pipeline as the widget (§2: queued, never inline).
                    GenerateChatbotReply::dispatch(
                        (string) $conversation->organization_id,
                        $conversation->id,
                    )->afterCommit();
                }
            }
        }

        return $handled;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{wamid: string, from: string, body: string, name: string|null}>
     */
    private function extractMessages(array $payload): array
    {
        $out = [];
        $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
            foreach ($changes as $change) {
                if (! is_array($change)) {
                    continue;
                }
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];

                // Profile names ride separately from messages.
                $names = [];
                foreach (is_array($value['contacts'] ?? null) ? $value['contacts'] : [] as $contact) {
                    if (is_array($contact) && is_string($contact['wa_id'] ?? null)) {
                        $profile = is_array($contact['profile'] ?? null) ? $contact['profile'] : [];
                        $names[$contact['wa_id']] = is_string($profile['name'] ?? null) ? $profile['name'] : null;
                    }
                }

                foreach (is_array($value['messages'] ?? null) ? $value['messages'] : [] as $message) {
                    if (! is_array($message) || ! is_string($message['id'] ?? null) || ! is_string($message['from'] ?? null)) {
                        continue;
                    }

                    $type = is_string($message['type'] ?? null) ? $message['type'] : 'unknown';
                    $text = is_array($message['text'] ?? null) ? $message['text'] : [];
                    $body = $type === 'text' && is_string($text['body'] ?? null)
                        ? $text['body']
                        : "[unsupported {$type} message]";

                    // Interactive button replies (Phase 33): the id carries
                    // the FULL option so FlowRunner branching matches even
                    // when the visible title was truncated to 20 chars.
                    if ($type === 'interactive') {
                        $interactive = is_array($message['interactive'] ?? null) ? $message['interactive'] : [];
                        $reply = is_array($interactive['button_reply'] ?? null) ? $interactive['button_reply'] : [];
                        $choice = $reply['id'] ?? $reply['title'] ?? null;
                        if (is_string($choice) && $choice !== '') {
                            $body = $choice;
                        }
                    }

                    $out[] = [
                        'wamid' => $message['id'],
                        'from' => $message['from'],
                        'body' => $body,
                        'name' => $names[$message['from']] ?? null,
                    ];
                }
            }
        }

        return $out;
    }
}
