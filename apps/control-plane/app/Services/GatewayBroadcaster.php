<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort live fan-out for messages persisted through NON-gateway paths
 * (agent REST replies, chatbot jobs, widget REST fallback): after the DB
 * transaction commits, POST the message to the gateway's internal broadcast
 * endpoint so WebSocket subscribers get instant delivery.
 *
 * INTERIM until the NATS backbone carries conv.message.accepted (ADR-005).
 * Contract: NEVER throws — realtime is an enhancement; polling remains the
 * delivery safety net (RULES-failure-retry).
 */
final class GatewayBroadcaster
{
    public function messageAccepted(Message $message): void
    {
        $url = config('services.gateway.internal_url');
        $token = config('services.gateway.internal_token');

        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            return; // Gateway fan-out not configured (e.g. tests, minimal deploys).
        }

        $payload = [
            'organization_id' => (string) $message->organization_id,
            'conversation_id' => (string) $message->conversation_id,
            'message_id' => $message->id,
            'sender_type' => $message->sender_type,
            'sender_id' => (string) $message->sender_id,
            'sequence_number' => (int) $message->sequence_number,
            'content_type' => $message->content_type,
            'body' => $message->body,
            'sent_at' => (string) $message->sent_at?->toIso8601String(),
        ];

        // After COMMIT only — a broadcast for a rolled-back message would be
        // a phantom (§27 ordering guarantees are DB-anchored).
        DB::afterCommit(function () use ($url, $token, $payload): void {
            try {
                Http::withToken($token)
                    ->timeout(2)
                    ->acceptJson()
                    ->post(rtrim($url, '/').'/internal/broadcast', $payload);
            } catch (\Throwable) {
                Log::info('gateway_broadcast_skipped', [
                    'conversation_id' => $payload['conversation_id'],
                ]);
            }
        });
    }
}
