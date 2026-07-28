<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateChatbotReply;
use App\Models\Channel;
use App\Models\Chatbot;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;

/**
 * Facebook Messenger inbound processing (Phase 32). Mirrors the WhatsApp/
 * Telegram services: tenant context established upstream, text events only
 * in v1, provider message ids (mid) hashed into idempotency keys.
 *
 * Identity: contacts keyed by external_id "fb:{psid}". Messenger webhooks
 * carry no profile name — a best-effort Graph API lookup fills it in and
 * quietly falls back to a placeholder.
 */
final class MessengerInbound
{
    public function __construct(
        private readonly ConversationMessenger $messenger,
        private readonly AssignmentService $assignments,
    ) {}

    /** @param array<string, mixed> $payload  the decoded webhook body */
    public function process(Channel $channel, array $payload): int
    {
        $handled = 0;

        foreach ($this->extractEvents($payload) as $event) {
            $contact = Contact::query()->firstOrCreate(
                ['external_id' => 'fb:'.$event['psid']],
                ['name' => $this->profileName($channel, $event['psid'])],
            );

            $conversation = Conversation::query()
                ->where('channel_id', $channel->id)
                ->where('external_thread_id', $event['psid'])
                ->where('status', '!=', 'closed')
                ->latest('created_at')
                ->first();

            if ($conversation === null) {
                $conversation = Conversation::query()->create([
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'external_thread_id' => $event['psid'],
                    'chatbot_id' => Chatbot::query()->where('status', 'active')->first()?->id,
                    'department_id' => Department::query()->where('is_default', true)->first()?->id,
                ]);
                $this->assignments->autoAssign($conversation);
            }

            $result = $this->messenger->send(
                conversation: $conversation,
                senderType: 'contact',
                senderId: $contact->id,
                body: $event['text'],
                idempotencyKey: Uuid::uuid5(Uuid::NAMESPACE_OID, 'fb:'.$event['mid'])->toString(),
                channelId: $channel->id,
            );

            if (! $result['duplicate']) {
                $handled++;
                if ($conversation->chatbot_id !== null) {
                    GenerateChatbotReply::dispatch(
                        (string) $conversation->organization_id,
                        $conversation->id,
                    )->afterCommit();
                }
            }
        }

        return $handled;
    }

    /** Best-effort Graph profile lookup; placeholder on any failure. */
    private function profileName(Channel $channel, string $psid): string
    {
        $base = config('services.messenger.base_url', 'https://graph.facebook.com/v20.0');

        try {
            $response = Http::timeout(5)->acceptJson()->get(
                rtrim(is_string($base) ? $base : '', '/')."/{$psid}",
                [
                    'fields' => 'first_name,last_name',
                    'access_token' => $channel->configString('access_token'),
                ],
            );

            if ($response->successful()) {
                $first = $response->json('first_name');
                $last = $response->json('last_name');
                $name = trim((is_string($first) ? $first : '').' '.(is_string($last) ? $last : ''));
                if ($name !== '') {
                    return $name;
                }
            }
        } catch (\Throwable) {
            // Profile is a nicety, never a blocker.
        }

        return 'Messenger '.substr($psid, 0, 8);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{mid: string, psid: string, text: string}>
     */
    private function extractEvents(array $payload): array
    {
        $out = [];
        $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            foreach (is_array($entry['messaging'] ?? null) ? $entry['messaging'] : [] as $event) {
                if (! is_array($event)) {
                    continue;
                }
                $sender = is_array($event['sender'] ?? null) ? $event['sender'] : [];
                $message = is_array($event['message'] ?? null) ? $event['message'] : null;
                $psid = $sender['id'] ?? null;

                if ($message === null || ! is_string($psid) || ! is_string($message['mid'] ?? null)) {
                    continue; // delivery/read/postback events — v2 territory.
                }
                if (($message['is_echo'] ?? false) === true) {
                    continue; // Our own outbound reflected back.
                }

                $out[] = [
                    'mid' => $message['mid'],
                    'psid' => $psid,
                    'text' => is_string($message['text'] ?? null) ? $message['text'] : '[unsupported message type]',
                ];
            }
        }

        return $out;
    }
}
