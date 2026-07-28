<?php

declare(strict_types=1);

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Organization;
use App\Services\TelegramInbound;
use App\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telegram Bot API webhook (Phase 31). PUBLIC endpoint — Telegram
 * authenticates via the X-Telegram-Bot-Api-Secret-Token header, which we
 * set to the channel's webhook_verify_token at setWebhook time
 * (constant-time compare; wrong/missing secret 403s, unknown pairs 404).
 */
final class TelegramWebhookController extends Controller
{
    public function receive(
        Request $request,
        Tenancy $tenancy,
        TelegramInbound $inbound,
        string $organizationId,
        string $channelId,
    ): Response {
        $throttle = 'tg-webhook:'.hash('sha256', $channelId);
        if (RateLimiter::tooManyAttempts($throttle, maxAttempts: 300)) {
            return response()->json(['status' => 429], 429);
        }
        RateLimiter::hit($throttle, decaySeconds: 60);

        if (Organization::query()->whereKey($organizationId)->first() === null) {
            abort(404);
        }

        $channel = $tenancy->run($organizationId, fn (): ?Channel => Channel::query()
            ->whereKey($channelId)
            ->where('type', 'telegram')
            ->where('status', 'active')
            ->first());
        abort_if($channel === null, 404);

        $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        abort_unless(hash_equals($channel->webhook_verify_token, $secret), 403);

        $update = $request->json()->all();

        $tenancy->run($organizationId, function () use ($inbound, $channel, $update): void {
            $inbound->process($channel, $update);
        });

        return response()->json(['ok' => true]);
    }
}
