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
            'type' => ['required', 'in:whatsapp'],
            'name' => ['required', 'string', 'max:100'],
            'phone_number_id' => ['required', 'string', 'max:64'],
            'waba_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'access_token' => ['required', 'string', 'max:512'],
            'app_secret' => ['required', 'string', 'max:128'],
        ]);

        $channel = Channel::query()->create([
            'type' => $validated['type'],
            'name' => $validated['name'],
            'status' => 'active',
            'config' => [
                'phone_number_id' => $validated['phone_number_id'],
                'waba_id' => $validated['waba_id'] ?? null,
                'access_token' => $validated['access_token'],
                'app_secret' => $validated['app_secret'],
            ],
            'webhook_verify_token' => Str::random(48),
        ]);

        $user = $request->user();
        AuditLogEntry::record(
            actor: $user instanceof User ? 'user:'.$user->id : 'system',
            action: 'channel.created',
            subject: $channel,
            context: ['type' => $channel->type],
            ip: $request->ip(),
        );

        return response()->json($this->toContract($request, $channel, includeSetup: true), 201);
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
            ."/api/channels/whatsapp/{$channel->organization_id}/{$channel->id}";

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
