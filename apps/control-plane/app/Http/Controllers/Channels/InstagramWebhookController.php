<?php

declare(strict_types=1);

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Organization;
use App\Services\InstagramInbound;
use App\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Instagram DM webhook. Instagram messaging rides the Meta Messenger Platform,
 * so the mechanics match Messenger/WhatsApp exactly: GET = hub.challenge
 * handshake against the channel's verify token, POST = X-Hub-Signature-256
 * HMAC over the raw body with the app secret.
 */
final class InstagramWebhookController extends Controller
{
    public function verify(Request $request, Tenancy $tenancy, string $organizationId, string $channelId): Response
    {
        $channel = $this->channel($tenancy, $organizationId, $channelId);
        abort_if($channel === null, 404);

        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        abort_unless(is_string($token) && hash_equals($channel->webhook_verify_token, $token), 403);

        return response(is_string($challenge) ? $challenge : 'ok', 200)
            ->header('Content-Type', 'text/plain');
    }

    public function receive(
        Request $request,
        Tenancy $tenancy,
        InstagramInbound $inbound,
        string $organizationId,
        string $channelId,
    ): Response {
        $throttle = 'ig-webhook:'.hash('sha256', $channelId);
        if (RateLimiter::tooManyAttempts($throttle, maxAttempts: 300)) {
            return response()->json(['status' => 429], 429);
        }
        RateLimiter::hit($throttle, decaySeconds: 60);

        $channel = $this->channel($tenancy, $organizationId, $channelId);
        abort_if($channel === null, 404);

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $channel->configString('app_secret'));
        abort_unless(hash_equals($expected, $signature), 403);

        $payload = $request->json()->all();

        $tenancy->run($organizationId, function () use ($inbound, $channel, $payload): void {
            $inbound->process($channel, $payload);
        });

        return response()->json(['status' => 'ok']);
    }

    private function channel(Tenancy $tenancy, string $organizationId, string $channelId): ?Channel
    {
        if (Organization::query()->whereKey($organizationId)->first() === null) {
            return null;
        }

        return $tenancy->run($organizationId, fn (): ?Channel => Channel::query()
            ->whereKey($channelId)
            ->where('type', 'instagram')
            ->where('status', 'active')
            ->first());
    }
}
