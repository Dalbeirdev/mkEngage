<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhooks;
use App\Models\ApiKey;
use App\Models\AuditLogEntry;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Developer platform administration (Phase 35, §15): scoped API keys
 * (plaintext returned exactly once) and webhook endpoints (secret returned
 * exactly once; every delivery HMAC-signed with it).
 */
final class DeveloperController extends Controller
{
    public const WEBHOOK_EVENTS = ['message.created', 'conversation.closed'];

    // ── API keys ────────────────────────────────────────────────────────────

    public function listKeys(TenantContext $context): JsonResponse
    {
        return response()->json([
            'data' => ApiKey::query()
                ->where('organization_id', $context->organizationId())
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (ApiKey $key): array => [
                    'api_key_id' => $key->id,
                    'name' => $key->name,
                    'prefix' => $key->prefix,
                    'last_used_at' => $key->last_used_at?->toIso8601String(),
                    'revoked_at' => $key->revoked_at?->toIso8601String(),
                    'created_at' => $key->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    public function createKey(Request $request, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $plaintext = 'mk_live_'.Str::random(40);

        $key = ApiKey::query()->create([
            'organization_id' => $context->organizationId(),
            'name' => $validated['name'],
            'prefix' => substr($plaintext, 0, 15).'…',
            'key_hash' => hash('sha256', $plaintext),
        ]);

        $this->audit($request, 'api_key.created', $key);

        // Plaintext is returned exactly once; only the hash is stored.
        return response()->json([
            'api_key_id' => $key->id,
            'name' => $key->name,
            'key' => $plaintext,
        ], 201);
    }

    public function revokeKey(Request $request, TenantContext $context, string $apiKeyId): JsonResponse
    {
        $key = ApiKey::query()
            ->where('organization_id', $context->organizationId())
            ->whereKey($apiKeyId)
            ->first();
        abort_if($key === null, 404);

        $key->forceFill(['revoked_at' => now()])->save();
        $this->audit($request, 'api_key.revoked', $key);

        return response()->json(null, 204);
    }

    // ── Webhook endpoints ───────────────────────────────────────────────────

    public function listWebhooks(): JsonResponse
    {
        return response()->json([
            'data' => WebhookEndpoint::query()->orderByDesc('created_at')->get()
                ->map(fn (WebhookEndpoint $endpoint): array => [
                    'webhook_endpoint_id' => $endpoint->id,
                    'url' => $endpoint->url,
                    'events' => $endpoint->events ?? [],
                    'status' => $endpoint->status,
                    'created_at' => $endpoint->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    public function createWebhook(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url:https,http', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['in:'.implode(',', self::WEBHOOK_EVENTS)],
        ]);

        $secret = 'whsec_'.Str::random(40);

        $endpoint = WebhookEndpoint::query()->create([
            'url' => $validated['url'],
            'secret' => $secret,
            'events' => array_values(array_unique($validated['events'])),
            'status' => 'active',
        ]);

        $this->audit($request, 'webhook_endpoint.created', $endpoint);

        // Secret shown exactly once — the customer verifies signatures with it.
        return response()->json([
            'webhook_endpoint_id' => $endpoint->id,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'secret' => $secret,
        ], 201);
    }

    public function deleteWebhook(Request $request, string $webhookEndpointId): JsonResponse
    {
        $endpoint = WebhookEndpoint::query()->find($webhookEndpointId);
        abort_if($endpoint === null, 404);

        $endpoint->delete();
        $this->audit($request, 'webhook_endpoint.deleted', $endpoint);

        return response()->json(null, 204);
    }

    /** Fire a signed test event so customers can verify their receiver. */
    public function testWebhook(Request $request, string $webhookEndpointId): JsonResponse
    {
        $endpoint = WebhookEndpoint::query()->find($webhookEndpointId);
        abort_if($endpoint === null, 404);

        DeliverWebhooks::dispatch(
            (string) $endpoint->organization_id,
            'webhook.test',
            ['message' => 'mkEngage webhook test', 'endpoint_id' => $endpoint->id],
        )->afterCommit();

        return response()->json(['status' => 'queued']);
    }

    private function audit(Request $request, string $action, ApiKey|WebhookEndpoint $subject): void
    {
        $user = $request->user();
        AuditLogEntry::record(
            actor: $user instanceof User ? 'user:'.$user->id : 'system',
            action: $action,
            subject: $subject,
            ip: $request->ip(),
        );
    }
}
