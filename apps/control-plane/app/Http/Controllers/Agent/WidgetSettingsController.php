<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\BusinessHours;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Widget installation settings (§2 widget configuration).
 *
 * Secret handling mirrors API keys (§15/ADR-009): the signing secret is
 * WRITE-ONLY — never retrievable after creation, only rotatable, and the new
 * value is returned exactly once. The site key is public by design (§4).
 * Rotation invalidates all previously computed identity signatures
 * immediately (fail closed — customers re-render pages with new HMACs).
 */
final class WidgetSettingsController extends Controller
{
    public function show(TenantContext $context): JsonResponse
    {
        $organization = $this->organization($context);
        $settings = is_array($organization->settings) ? $organization->settings : [];
        $prechat = is_array($settings['prechat'] ?? null) ? $settings['prechat'] : [];
        $hours = is_array($settings['business_hours'] ?? null) ? $settings['business_hours'] : [];

        return response()->json([
            'site_key' => $organization->widget_site_key,
            'signing_configured' => $organization->widget_signing_secret !== null
                && $organization->widget_signing_secret !== '',
            'prechat' => [
                'enabled' => ($prechat['enabled'] ?? false) === true,
                'require_email' => ($prechat['require_email'] ?? false) === true,
            ],
            'business_hours' => [
                'enabled' => ($hours['enabled'] ?? false) === true,
                'timezone' => is_string($hours['timezone'] ?? null) ? $hours['timezone'] : 'UTC',
                'schedule' => is_array($hours['schedule'] ?? null) ? $hours['schedule'] : (object) [],
            ],
        ]);
    }

    /**
     * Phase 23: pre-chat + business-hours configuration. Stored in
     * organizations.settings (json); validated strictly so the widget-side
     * evaluator only ever sees well-formed schedules.
     */
    public function update(Request $request, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'prechat' => ['sometimes', 'array:enabled,require_email'],
            'prechat.enabled' => ['required_with:prechat', 'boolean'],
            'prechat.require_email' => ['sometimes', 'boolean'],
            'business_hours' => ['sometimes', 'array:enabled,timezone,schedule'],
            'business_hours.enabled' => ['required_with:business_hours', 'boolean'],
            'business_hours.timezone' => ['sometimes', 'timezone:all'],
            'business_hours.schedule' => ['sometimes', 'array:'.implode(',', BusinessHours::DAY_KEYS)],
            'business_hours.schedule.*' => ['array', 'max:6'],
            'business_hours.schedule.*.*' => ['array', 'size:2'],
            'business_hours.schedule.*.*.*' => ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        $organization = $this->organization($context);
        $settings = is_array($organization->settings) ? $organization->settings : [];

        if (array_key_exists('prechat', $validated)) {
            $settings['prechat'] = [
                'enabled' => (bool) ($validated['prechat']['enabled'] ?? false),
                'require_email' => (bool) ($validated['prechat']['require_email'] ?? false),
            ];
        }

        if (array_key_exists('business_hours', $validated)) {
            $settings['business_hours'] = [
                'enabled' => (bool) ($validated['business_hours']['enabled'] ?? false),
                'timezone' => $validated['business_hours']['timezone'] ?? 'UTC',
                'schedule' => $validated['business_hours']['schedule'] ?? [],
            ];
        }

        $organization->settings = $settings;
        $organization->save();

        $user = $request->user();
        AuditLogEntry::record(
            actor: $user instanceof User ? 'user:'.$user->id : 'system',
            action: 'widget.settings.updated',
            subject: $organization,
            ip: $request->ip(),
        );

        return $this->show($context);
    }

    public function rotateSecret(Request $request, TenantContext $context): JsonResponse
    {
        $organization = $this->organization($context);

        $secret = 'whsec_'.Str::random(40);
        $organization->widget_signing_secret = $secret;
        $organization->save();

        $user = $request->user();
        AuditLogEntry::record(
            actor: $user instanceof User ? 'user:'.$user->id : 'system',
            action: 'widget.signing_secret.rotated',
            subject: $organization,
            ip: $request->ip(),
        );

        // Returned exactly once; stored encrypted (§18).
        return response()->json(['signing_secret' => $secret], 201);
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->whereKey($context->organizationId())->firstOrFail();
    }
}
