<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
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

            if ($channel === null || ! in_array($channel->type, ['whatsapp', 'telegram', 'messenger'], true)) {
                return;
            }

            try {
                $response = match ($channel->type) {
                    'telegram' => $this->sendTelegram($channel, $conversation->external_thread_id, $message),
                    'messenger' => $this->sendMessenger($channel, $conversation->external_thread_id, $message),
                    default => $this->sendWhatsApp($channel, $conversation->external_thread_id, $message),
                };
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

    private function sendWhatsApp(Channel $channel, string $to, Message $message): Response
    {
        $base = config('services.whatsapp.base_url', 'https://graph.facebook.com/v20.0');

        // Phase 33: rich flow menus with ≤3 options ship as REAL interactive
        // reply buttons (the Cloud API's cap); more options fall back to the
        // bullet-list text rendering.
        $options = $this->richOptions($message);
        $payload = ['messaging_product' => 'whatsapp', 'to' => $to];

        if ($options !== [] && count($options) <= 3) {
            $payload['type'] = 'interactive';
            $payload['interactive'] = [
                'type' => 'button',
                'body' => ['text' => $this->renderBody($message, includeOptionsInText: false)],
                'action' => [
                    'buttons' => array_map(fn (string $option): array => [
                        'type' => 'reply',
                        // 20-char title cap; the full option rides in the id
                        // so flow branching still matches on the reply.
                        'reply' => ['id' => mb_substr($option, 0, 256), 'title' => mb_substr($option, 0, 20)],
                    ], $options),
                ],
            ];
        } else {
            $payload['type'] = 'text';
            $payload['text'] = ['body' => $this->renderBody($message)];
        }

        return Http::withToken($channel->configString('access_token'))
            ->timeout(15)
            ->acceptJson()
            ->post(
                rtrim(is_string($base) ? $base : '', '/')
                    .'/'.$channel->configString('phone_number_id').'/messages',
                $payload,
            );
    }

    /** Telegram gets REAL buttons: rich flow menus become a reply keyboard. */
    private function sendTelegram(Channel $channel, string $chatId, Message $message): Response
    {
        $base = config('services.telegram.base_url', 'https://api.telegram.org');
        $payload = [
            'chat_id' => $chatId,
            'text' => $this->renderBody($message, includeOptionsInText: false),
        ];

        $options = $this->richOptions($message);
        if ($options !== []) {
            $payload['reply_markup'] = [
                'keyboard' => array_map(fn (string $option): array => [['text' => $option]], $options),
                'one_time_keyboard' => true,
                'resize_keyboard' => true,
            ];
        }

        return Http::timeout(15)
            ->acceptJson()
            ->post(
                rtrim(is_string($base) ? $base : '', '/')
                    .'/bot'.$channel->configString('bot_token').'/sendMessage',
                $payload,
            );
    }

    /** Messenger Send API — rich flow menus become quick-reply buttons. */
    private function sendMessenger(Channel $channel, string $psid, Message $message): Response
    {
        $base = config('services.messenger.base_url', 'https://graph.facebook.com/v20.0');
        $messagePayload = ['text' => $this->renderBody($message, includeOptionsInText: false)];

        $options = $this->richOptions($message);
        if ($options !== []) {
            $messagePayload['quick_replies'] = array_map(fn (string $option): array => [
                'content_type' => 'text',
                'title' => mb_substr($option, 0, 20), // Messenger's title cap
                'payload' => $option,
            ], $options);
        }

        return Http::withToken($channel->configString('access_token'))
            ->timeout(15)
            ->acceptJson()
            ->post(
                rtrim(is_string($base) ? $base : '', '/').'/me/messages',
                [
                    'recipient' => ['id' => $psid],
                    'messaging_type' => 'RESPONSE',
                    'message' => $messagePayload,
                ],
            );
    }

    /** @return list<string> options of a rich (flow menu) message, else []. */
    private function richOptions(Message $message): array
    {
        if ($message->content_type !== 'rich') {
            return [];
        }
        $decoded = json_decode($message->body, true);
        if (! is_array($decoded) || ! is_array($decoded['options'] ?? null)) {
            return [];
        }

        return array_values(array_filter($decoded['options'], is_string(...)));
    }

    /** Rich flow menus degrade to a text bullet list on WhatsApp (v1). */
    private function renderBody(Message $message, bool $includeOptionsInText = true): string
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

        return $options === [] || ! $includeOptionsInText
            ? $text
            : $text."\n\n".implode("\n", array_map(fn (string $option): string => "• {$option}", $options));
    }
}
