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
 * Inbound email processing. Tenant context is established upstream. Contacts
 * are keyed by external_id "email:{address}"; a thread is reused per address.
 * The subject line (when present) rides at the top of the first message body.
 */
final class EmailInbound
{
    public function __construct(
        private readonly ConversationMessenger $messenger,
        private readonly AssignmentService $assignments,
    ) {}

    /** @param array{address: string, subject: string, text: string, message_id: string} $data */
    public function process(Channel $channel, array $data): int
    {
        $contact = Contact::query()->firstOrCreate(
            ['external_id' => 'email:'.$data['address']],
            ['name' => $data['address'], 'email' => $data['address']],
        );

        $conversation = Conversation::query()
            ->where('channel_id', $channel->id)
            ->where('external_thread_id', $data['address'])
            ->where('status', '!=', 'closed')
            ->latest('created_at')
            ->first();

        if ($conversation === null) {
            $conversation = Conversation::query()->create([
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'external_thread_id' => $data['address'],
                'chatbot_id' => Chatbot::query()->where('status', 'active')->first()?->id,
                'department_id' => Department::query()->where('is_default', true)->first()?->id,
            ]);
            $this->assignments->autoAssign($conversation);
        }

        $subject = trim($data['subject']);
        $body = $subject !== '' ? $subject."\n\n".$data['text'] : $data['text'];
        if (trim($body) === '') {
            $body = '[empty email]';
        }

        // Dedupe provider retries: prefer the Message-Id, else hash the content.
        $key = $data['message_id'] !== ''
            ? 'email-mid:'.$data['message_id']
            : 'email:'.$data['address'].'|'.sha1($subject.'|'.$data['text']);

        $result = $this->messenger->send(
            conversation: $conversation,
            senderType: 'contact',
            senderId: $contact->id,
            body: $body,
            idempotencyKey: Uuid::uuid5(Uuid::NAMESPACE_OID, $key)->toString(),
            channelId: $channel->id,
        );

        if (! $result['duplicate']) {
            if ($conversation->chatbot_id !== null) {
                GenerateChatbotReply::dispatch(
                    (string) $conversation->organization_id,
                    $conversation->id,
                )->afterCommit();
            }

            return 1;
        }

        return 0;
    }
}
