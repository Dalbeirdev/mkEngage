<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Third-party integrations config (org settings). Slack in v1: an incoming
 * webhook URL that receives new-conversation notifications. The URL is
 * WRITE-ONLY — never returned — only its configured/enabled state is.
 */
final class IntegrationController extends Controller
{
    public function show(TenantContext $context): JsonResponse
    {
        $slack = $this->slackConfig($this->organization($context));

        return response()->json([
            'slack' => [
                'enabled' => ($slack['enabled'] ?? false) === true,
                'configured' => is_string($slack['webhook_url'] ?? null) && $slack['webhook_url'] !== '',
            ],
        ]);
    }

    public function updateSlack(Request $request, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            // Slack incoming webhooks live under hooks.slack.com; accept any
            // https URL so self-hosted relays work too.
            'webhook_url' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
        ]);

        $organization = $this->organization($context);
        $settings = is_array($organization->settings) ? $organization->settings : [];
        $integrations = is_array($settings['integrations'] ?? null) ? $settings['integrations'] : [];
        $slack = is_array($integrations['slack'] ?? null) ? $integrations['slack'] : [];

        $slack['enabled'] = (bool) $validated['enabled'];
        if (array_key_exists('webhook_url', $validated)) {
            // Empty/null clears it; a value replaces it (write-only).
            $slack['webhook_url'] = is_string($validated['webhook_url']) && $validated['webhook_url'] !== ''
                ? $validated['webhook_url']
                : '';
        }

        $integrations['slack'] = $slack;
        $settings['integrations'] = $integrations;
        $organization->settings = $settings;
        $organization->save();

        $this->audit($request, 'integration.slack.updated', $organization);

        return $this->show($context);
    }

    /** Fire a test message to the configured Slack webhook. */
    public function testSlack(TenantContext $context): JsonResponse
    {
        $slack = $this->slackConfig($this->organization($context));
        $url = is_string($slack['webhook_url'] ?? null) ? $slack['webhook_url'] : '';

        if ($url === '') {
            return response()->json(['delivered' => false, 'reason' => 'not_configured'], 422);
        }

        try {
            $response = Http::timeout(10)->post($url, ['text' => '✅ mkEngage is connected to this Slack channel.']);

            return response()->json(['delivered' => $response->successful()]);
        } catch (\Throwable) {
            return response()->json(['delivered' => false, 'reason' => 'transport']);
        }
    }

    /** @return array<string, mixed> */
    private function slackConfig(Organization $organization): array
    {
        $settings = is_array($organization->settings) ? $organization->settings : [];
        $integrations = is_array($settings['integrations'] ?? null) ? $settings['integrations'] : [];

        return is_array($integrations['slack'] ?? null) ? $integrations['slack'] : [];
    }

    private function audit(Request $request, string $action, Organization $subject): void
    {
        $user = $request->user();
        AuditLogEntry::record(
            actor: $user instanceof User ? 'user:'.$user->id : 'system',
            action: $action,
            subject: $subject,
            ip: $request->ip(),
        );
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->whereKey($context->organizationId())->firstOrFail();
    }
}
