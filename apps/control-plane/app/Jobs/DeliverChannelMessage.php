<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Deliver an agent/chatbot reply to the conversation's channel provider
 * (Phase 29 — WhatsApp Cloud API first).
 *
 * Failure policy mirrors the AI job: provider outages never break chat —
 * everything is logged and swallowed (durable retry orchestration lands
 * with Temporal, ADR-004).
 */
final class DeliverChannelMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $organizationId,
        private readonly string $messageId,
    ) {}

    public function handle(Tenancy $tenancy): void
    {
        $tenancy->run($this->organizationId, function (): void {
            $message = Message::query()->find($this->messageId);
            if ($message === null) {
                return;
            }

            $conversation = Conversation::query()->find($message->conversation_id);
            if ($conversation === null || $conversation->external_thread_id === null) {
                return;
            }

            $channel = $conversation->channel_id !== null
                ? Channel::query()->whereKey($conversation->channel_id)->where('status', 'active')->first()
                : null;

            if ($channel === null || $channel->type !== 'whatsapp') {
                return;
            }

            $base = config('services.whatsapp.base_url', 'https://graph.facebook.com/v20.0');

            try {
                $response = Http::withToken($channel->configString('access_token'))
                    ->timeout(15)
                    ->acceptJson()
                    ->post(
                        rtrim(is_string($base) ? $base : '', '/')
                            .'/'.$channel->configString('phone_number_id').'/messages',
                        [
                            'messaging_product' => 'whatsapp',
                            'to' => $conversation->external_thread_id,
                            'type' => 'text',
                            'text' => ['body' => $this->renderBody($message)],
                        ],
                    );
            } catch (\Throwable) {
                Log::warning('channel_delivery_failed', [
                    'organization_id' => $this->organizationId,
                    'message_id' => $this->messageId,
                    'reason' => 'transport',
                ]);

                return;
            }

            if ($response->failed()) {
                Log::warning('channel_delivery_failed', [
                    'organization_id' => $this->organizationId,
                    'message_id' => $this->messageId,
                    'status' => $response->status(),
                ]);
            }
        });
    }

    /** Rich flow menus degrade to a text bullet list on WhatsApp (v1). */
    private function renderBody(Message $message): string
    {
        if ($message->content_type !== 'rich') {
            return $message->body;
        }

        $decoded = json_decode($message->body, true);
        if (! is_array($decoded)) {
            return $message->body;
        }

        $text = is_string($decoded['text'] ?? null) ? $decoded['text'] : '';
        $options = is_array($decoded['options'] ?? null)
            ? array_filter($decoded['options'], is_string(...))
            : [];

        return $options === []
            ? $text
            : $text."\n\n".implode("\n", array_map(fn (string $option): string => "• {$option}", $options));
    }
}
