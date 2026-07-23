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

        return response()->json([
            'site_key' => $organization->widget_site_key,
            'signing_configured' => $organization->widget_signing_secret !== null
                && $organization->widget_signing_secret !== '',
        ]);
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
