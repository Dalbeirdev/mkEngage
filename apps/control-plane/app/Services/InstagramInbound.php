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
 * Instagram DM inbound processing. Instagram messaging uses the Messenger
 * Platform webhook shape (entry[].messaging[]), so this mirrors the Messenger
 * service: tenant context established upstream, text events only in v1,
 * provider message ids (mid) hashed into idempotency keys.
 *
 * Identity: contacts keyed by external_id "ig:{igsid}". A best-effort Graph
 * lookup fills the display name (name, then @username), falling back to a
 * placeholder.
 */
final class InstagramInbound
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
                ['external_id' => 'ig:'.$event['igsid']],
                ['name' => $this->profileName($channel, $event['igsid'])],
            );

            $conversation = Conversation::query()
                ->where('channel_id', $channel->id)
                ->where('external_thread_id', $event['igsid'])
                ->where('status', '!=', 'closed')
                ->latest('created_at')
                ->first();

            if ($conversation === null) {
                $conversation = Conversation::query()->create([
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'external_thread_id' => $event['igsid'],
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
                idempotencyKey: Uuid::uuid5(Uuid::NAMESPACE_OID, 'ig:'.$event['mid'])->toString(),
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

    /** Best-effort Graph profile lookup (name, then @username); placeholder on failure. */
    private function profileName(Channel $channel, string $igsid): string
    {
        $base = config('services.instagram.base_url', 'https://graph.facebook.com/v20.0');

        try {
            $response = Http::timeout(5)->acceptJson()->get(
                rtrim(is_string($base) ? $base : '', '/')."/{$igsid}",
                [
                    'fields' => 'name,username',
                    'access_token' => $channel->configString('access_token'),
                ],
            );

            if ($response->successful()) {
                $name = $response->json('name');
                if (is_string($name) && trim($name) !== '') {
                    return trim($name);
                }
                $username = $response->json('username');
                if (is_string($username) && trim($username) !== '') {
                    return '@'.trim($username);
                }
            }
        } catch (\Throwable) {
            // Profile is a nicety, never a blocker.
        }

        return 'Instagram '.substr($igsid, 0, 8);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{mid: string, igsid: string, text: string}>
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
                $igsid = $sender['id'] ?? null;

                if ($message === null || ! is_string($igsid) || ! is_string($message['mid'] ?? null)) {
                    continue; // reactions/read/postback events — v2 territory.
                }
                if (($message['is_echo'] ?? false) === true) {
                    continue; // Our own outbound reflected back.
                }

                $out[] = [
                    'mid' => $message['mid'],
                    'igsid' => $igsid,
                    'text' => is_string($message['text'] ?? null) ? $message['text'] : '[unsupported message type]',
                ];
            }
        }

        return $out;
    }
}
