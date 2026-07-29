<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Organization;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Post a "new conversation" notification to the org's Slack incoming webhook.
 *
 * Failure policy mirrors channel delivery: a Slack outage never affects chat —
 * everything is logged and swallowed. No-op when the integration is disabled
 * or unconfigured.
 */
final class NotifySlack implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $organizationId,
        private readonly string $conversationId,
        private readonly string $preview,
    ) {}

    public function handle(Tenancy $tenancy): void
    {
        $tenancy->run($this->organizationId, function (): void {
            $organization = Organization::query()->find($this->organizationId);
            if ($organization === null) {
                return;
            }

            $settings = is_array($organization->settings) ? $organization->settings : [];
            $integrations = is_array($settings['integrations'] ?? null) ? $settings['integrations'] : [];
            $slack = is_array($integrations['slack'] ?? null) ? $integrations['slack'] : [];

            $url = is_string($slack['webhook_url'] ?? null) ? $slack['webhook_url'] : '';
            if (($slack['enabled'] ?? false) !== true || $url === '') {
                return;
            }

            $conversation = Conversation::query()->with(['contact:id,name', 'channel:id,type'])->find($this->conversationId);
            $contactName = data_get($conversation, 'contact.name');
            $channelType = data_get($conversation, 'channel.type');
            $who = is_string($contactName) && $contactName !== '' ? $contactName : 'A visitor';
            $channel = is_string($channelType) && $channelType !== '' ? $channelType : 'web';

            try {
                Http::timeout(10)->post($url, [
                    'text' => "🆕 *New conversation* from {$who} via {$channel}\n> ".$this->preview,
                ]);
            } catch (\Throwable) {
                Log::warning('slack_notify_failed', ['organization_id' => $this->organizationId]);
            }
        });
    }
}
