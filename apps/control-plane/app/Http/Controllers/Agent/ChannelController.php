<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Channel;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Channel administration (Phase 29). Credentials are WRITE-ONLY: stored
 * encrypted, never returned. The webhook URL + verify token ARE returned —
 * the admin pastes them into the Meta App console.
 */
final class ChannelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Channel::query()->orderBy('created_at')->get()
                ->map(fn (Channel $channel): array => $this->toContract($request, $channel))
                ->all(),
        ]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:whatsapp,telegram,messenger,instagram,email'],
            'name' => ['required', 'string', 'max:100'],
            // Email channel: the address replies are sent from.
            'from_address' => ['required_if:type,email', 'email', 'max:255'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            // Optional per-org SMTP (else the global mailer is used).
            'smtp_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'smtp_port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'smtp_password' => ['sometimes', 'nullable', 'string', 'max:512'],
            'smtp_encryption' => ['sometimes', 'nullable', 'in:tls,ssl'],
            // WhatsApp Cloud API credentials
            'phone_number_id' => ['required_if:type,whatsapp', 'string', 'max:64'],
            'waba_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'access_token' => ['required_if:type,whatsapp,messenger,instagram', 'string', 'max:512'],
            'app_secret' => ['required_if:type,whatsapp,messenger,instagram', 'string', 'max:128'],
            // Messenger page
            'page_id' => ['required_if:type,messenger', 'string', 'max:64'],
            // Instagram professional account id (optional; the token's page owns it)
            'ig_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Telegram Bot API credential
            'bot_token' => ['required_if:type,telegram', 'string', 'max:128'],
        ]);

        $config = match ($validated['type']) {
            'telegram' => ['bot_token' => $validated['bot_token']],
            'messenger' => [
                'page_id' => $validated['page_id'],
                'access_token' => $validated['access_token'],
                'app_secret' => $validated['app_secret'],
            ],
            'instagram' => [
                'ig_id' => $validated['ig_id'] ?? null,
                'access_token' => $validated['access_token'],
                'app_secret' => $validated['app_secret'],
            ],
            'email' => [
                'from_address' => $validated['from_address'],
                'from_name' => $validated['from_name'] ?? null,
                'smtp_host' => $validated['smtp_host'] ?? null,
                'smtp_port' => $validated['smtp_port'] ?? null,
                'smtp_username' => $validated['smtp_username'] ?? null,
                'smtp_password' => $validated['smtp_password'] ?? null,
                'smtp_encryption' => $validated['smtp_encryption'] ?? null,
            ],
            default => [
                'phone_number_id' => $validated['phone_number_id'],
                'waba_id' => $validated['waba_id'] ?? null,
                'access_token' => $validated['access_token'],
                'app_secret' => $validated['app_secret'],
            ],
        };

        $channel = Channel::query()->create([
            'type' => $validated['type'],
            'name' => $validated['name'],
            'status' => 'active',
            'config' => $config,
            'webhook_verify_token' => Str::random(48),
        ]);

        // Telegram can self-register (Phase 31): best-effort setWebhook with
        // our URL + secret. Fails harmlessly on non-public hosts — the
        // response says so and the admin can re-register once deployed.
        $webhookRegistered = null;
        if ($channel->type === 'telegram') {
            $webhookRegistered = $this->registerTelegramWebhook($request, $channel);
        }

        $user = $request->user();
        AuditLogEntry::record(
            actor: $user instanceof User ? 'user:'.$user->id : 'system',
            action: 'channel.created',
            subject: $channel,
            context: ['type' => $channel->type],
            ip: $request->ip(),
        );

        return response()->json([
            ...$this->toContract($request, $channel, includeSetup: true),
            ...($webhookRegistered !== null ? ['webhook_registered' => $webhookRegistered] : []),
        ], 201);
    }

    /**
     * POST setWebhook to Telegram; swallow failures (non-public local hosts).
     * $hostOverride lets an admin register against a public tunnel URL while
     * calling the API from localhost (Phase 36 repair).
     */
    private function registerTelegramWebhook(Request $request, Channel $channel, ?string $hostOverride = null): bool
    {
        $base = config('services.telegram.base_url', 'https://api.telegram.org');
        $host = $hostOverride !== null && $hostOverride !== ''
            ? rtrim($hostOverride, '/')
            : rtrim($request->getSchemeAndHttpHost(), '/');
        $url = $host."/api/channels/telegram/{$channel->organization_id}/{$channel->id}";

        try {
            $response = Http::timeout(10)->acceptJson()->asForm()->post(
                rtrim(is_string($base) ? $base : '', '/').'/bot'.$channel->configString('bot_token').'/setWebhook',
                [
                    'url' => $url,
                    'secret_token' => $channel->webhook_verify_token,
                    // message_reaction is NOT sent by default — request it so
                    // customer emoji reactions sync to the inbox (Phase 38).
                    'allowed_updates' => json_encode(['message', 'edited_message', 'message_reaction']),
                ],
            );

            return $response->successful() && $response->json('ok') === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Re-run Telegram setWebhook, optionally against a public tunnel host
     * (Phase 36). Local creates register against 127.0.0.1 which Telegram
     * rejects; POST {host} here to point the webhook at a reachable URL.
     */
    public function registerWebhook(Request $request, string $channelId): JsonResponse
    {
        $channel = Channel::query()->whereKey($channelId)->where('type', 'telegram')->first();
        abort_if($channel === null, 404);

        $validated = $request->validate([
            'host' => ['sometimes', 'url:https,http', 'max:2048'],
        ]);

        $registered = $this->registerTelegramWebhook($request, $channel, $validated['host'] ?? null);

        return response()->json(['webhook_registered' => $registered]);
    }

    public function destroy(Request $request, string $channelId): JsonResponse
    {
        $channel = Channel::query()->find($channelId);
        abort_if($channel === null, 404);

        $channel->delete();

        $user = $request->user();
        AuditLogEntry::record(
            actor: $user instanceof User ? 'user:'.$user->id : 'system',
            action: 'channel.deleted',
            subject: $channel,
            ip: $request->ip(),
        );

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function toContract(Request $request, Channel $channel, bool $includeSetup = false): array
    {
        $webhookUrl = rtrim($request->getSchemeAndHttpHost(), '/')
            ."/api/channels/{$channel->type}/{$channel->organization_id}/{$channel->id}";

        return [
            'channel_id' => $channel->id,
            'type' => $channel->type,
            'name' => $channel->name,
            'status' => $channel->status,
            'webhook_url' => $webhookUrl,
            // Needed once, in the Meta console — shown on create AND list so
            // setup can resume; it authorizes nothing beyond the handshake.
            'webhook_verify_token' => $channel->webhook_verify_token,
            'created_at' => $channel->created_at?->toIso8601String(),
            ...$includeSetup ? [] : [],
        ];
    }
}
