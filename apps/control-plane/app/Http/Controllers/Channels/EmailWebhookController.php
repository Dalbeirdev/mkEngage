<?php

declare(strict_types=1);

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Organization;
use App\Services\EmailInbound;
use App\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbound email webhook. Email providers (SendGrid Inbound Parse, Postmark,
 * Mailgun routes) POST a parsed message to a configured URL. There is no
 * universal HMAC, so we authenticate with a per-channel secret token in the
 * X-Webhook-Token header (mirrors the Telegram secret-token approach). Field
 * names vary by provider, so the parse is deliberately tolerant.
 */
final class EmailWebhookController extends Controller
{
    public function receive(
        Request $request,
        Tenancy $tenancy,
        EmailInbound $inbound,
        string $organizationId,
        string $channelId,
    ): Response {
        $throttle = 'email-webhook:'.hash('sha256', $channelId);
        if (RateLimiter::tooManyAttempts($throttle, maxAttempts: 300)) {
            return response()->json(['status' => 429], 429);
        }
        RateLimiter::hit($throttle, decaySeconds: 60);

        $channel = $this->channel($tenancy, $organizationId, $channelId);
        abort_if($channel === null, 404);

        $token = (string) $request->header('X-Webhook-Token', '');
        abort_unless($token !== '' && hash_equals($channel->webhook_verify_token, $token), 403);

        $data = $this->parse($request);
        if ($data === null) {
            return response()->json(['status' => 'ignored']); // no usable sender
        }

        $tenancy->run($organizationId, function () use ($inbound, $channel, $data): void {
            $inbound->process($channel, $data);
        });

        return response()->json(['status' => 'ok']);
    }

    /**
     * Normalize a provider payload to {address, subject, text, message_id}.
     * Returns null when no sender address can be found.
     *
     * @return array{address: string, subject: string, text: string, message_id: string}|null
     */
    private function parse(Request $request): ?array
    {
        $from = $this->firstString($request, ['from', 'From', 'sender', 'FromFull']);
        $address = $this->extractAddress($from);
        if ($address === null) {
            return null;
        }

        return [
            'address' => $address,
            'subject' => $this->firstString($request, ['subject', 'Subject']) ?? '',
            'text' => $this->firstString($request, ['text', 'TextBody', 'plain', 'body', 'stripped-text']) ?? '',
            'message_id' => $this->firstString($request, ['message_id', 'MessageID', 'Message-Id', 'message-id']) ?? '',
        ];
    }

    /** @param list<string> $keys */
    private function firstString(Request $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $request->input($key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** Pull the bare address out of "Name <addr@host>" / "addr@host". */
    private function extractAddress(?string $from): ?string
    {
        if ($from === null) {
            return null;
        }
        if (preg_match('/<([^>]+)>/', $from, $m) === 1) {
            $from = $m[1];
        }
        $from = mb_strtolower(trim($from));

        return filter_var($from, FILTER_VALIDATE_EMAIL) === false ? null : $from;
    }

    private function channel(Tenancy $tenancy, string $organizationId, string $channelId): ?Channel
    {
        if (Organization::query()->whereKey($organizationId)->first() === null) {
            return null;
        }

        return $tenancy->run($organizationId, fn (): ?Channel => Channel::query()
            ->whereKey($channelId)
            ->where('type', 'email')
            ->where('status', 'active')
            ->first());
    }
}
