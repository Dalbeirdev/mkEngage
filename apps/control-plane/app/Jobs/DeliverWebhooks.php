<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookEndpoint;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Deliver one event to every subscribed webhook endpoint of the org
 * (Phase 35, §15). Signature: X-MkEngage-Signature: sha256=HMAC-SHA256 of
 * the RAW body with the endpoint's secret — same verification recipe we
 * demand of Meta.
 *
 * Failure policy: log-and-swallow per endpoint (one dead receiver must not
 * starve the others); durable retries arrive with Temporal (ADR-004).
 */
final class DeliverWebhooks implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $data */
    public function __construct(
        private readonly string $organizationId,
        private readonly string $event,
        private readonly array $data,
    ) {}

    public function handle(Tenancy $tenancy): void
    {
        $tenancy->run($this->organizationId, function (): void {
            $endpoints = WebhookEndpoint::query()
                ->where('status', 'active')
                ->get()
                ->filter(fn (WebhookEndpoint $endpoint): bool => $this->event === 'webhook.test'
                    || in_array($this->event, $endpoint->events ?? [], true));

            if ($endpoints->isEmpty()) {
                return;
            }

            $body = json_encode([
                'id' => 'evt_'.Str::uuid7()->toString(),
                'event' => $this->event,
                'created_at' => now()->toIso8601String(),
                'data' => $this->data,
            ]);
            if ($body === false) {
                return;
            }

            foreach ($endpoints as $endpoint) {
                $signature = 'sha256='.hash_hmac('sha256', $body, (string) $endpoint->secret);

                try {
                    $response = Http::timeout(10)
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'X-MkEngage-Event' => $this->event,
                            'X-MkEngage-Signature' => $signature,
                        ])
                        ->withBody($body, 'application/json')
                        ->post($endpoint->url);

                    if ($response->failed()) {
                        Log::warning('webhook_delivery_failed', [
                            'organization_id' => $this->organizationId,
                            'endpoint_id' => $endpoint->id,
                            'event' => $this->event,
                            'status' => $response->status(),
                        ]);
                    }
                } catch (\Throwable) {
                    Log::warning('webhook_delivery_failed', [
                        'organization_id' => $this->organizationId,
                        'endpoint_id' => $endpoint->id,
                        'event' => $this->event,
                        'reason' => 'transport',
                    ]);
                }
            }
        });
    }
}
