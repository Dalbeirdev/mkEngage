<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Visitor;
use App\Services\BusinessHours;
use App\Services\GeoLocator;
use App\Services\ModerationService;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Widget bootstrap: public site key → visitor identity + widget-scoped token.
 *
 * The site key is public by design (§4: the widget holds no secrets); origin
 * allow-listing and rate limits are the abuse controls (edge WAF adds more in
 * production, ADR-009). Passing an existing visitor_id restores the identity
 * ONLY when the presented token also matches — an unverified visitor_id claim
 * creates a fresh identity instead (no visitor takeover).
 */
final class WidgetSessionController extends Controller
{
    public function __invoke(Request $request, Tenancy $tenancy, BusinessHours $businessHours, GeoLocator $geo, ModerationService $moderation): JsonResponse
    {
        $validated = $request->validate([
            'site_key' => ['required', 'string', 'max:40'],
            'consent_state' => ['sometimes', 'in:granted,denied,unknown'],
        ]);

        $throttle = 'widget-session:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($throttle, maxAttempts: 30)) {
            return response()->json(['title' => 'Too many requests', 'status' => 429], 429);
        }
        RateLimiter::hit($throttle, decaySeconds: 60);

        $organization = Organization::query()
            ->where('widget_site_key', $validated['site_key'])
            ->first();

        if ($organization === null) {
            // Same shape as success-not-found: no site-key oracle.
            return response()->json(['title' => 'Unknown site key', 'status' => 404], 404);
        }

        // Coarse location from the edge proxy's geo headers (no IP stored).
        $location = $geo->locate($request);
        $ip = (string) $request->ip();

        /** @var array{visitor_id: string, token: string} $session */
        $session = $tenancy->run($organization->id, function () use ($organization, $validated, $location, $ip, $moderation): array {
            // Moderation: a banned IP is refused before any visitor identity or
            // token exists — the ban list is org-scoped, so this check needs the
            // tenant context established by run().
            abort_if($moderation->isIpBanned($ip), 403, 'Access denied.');

            $visitor = Visitor::query()->create([
                'consent_state' => $validated['consent_state'] ?? 'unknown',
                'last_seen_at' => now(),
                'country_code' => $location['country_code'],
                'city' => $location['city'],
            ]);

            $token = $visitor->createToken('widget', ['widget']);
            $token->accessToken->forceFill(['organization_id' => $organization->id])->save();

            return ['visitor_id' => $visitor->id, 'token' => $token->plainTextToken];
        });

        // Phase 23: advertise widget behavior config so the widget can render
        // the pre-chat form and offline notice without extra round-trips.
        // Config values only — never org internals.
        $settings = is_array($organization->settings) ? $organization->settings : [];
        $prechat = is_array($settings['prechat'] ?? null) ? $settings['prechat'] : [];

        $session['prechat'] = [
            'enabled' => ($prechat['enabled'] ?? false) === true,
            'require_email' => ($prechat['require_email'] ?? false) === true,
        ];
        $session['open'] = $businessHours->isOpen($organization);

        // Phase 24: enabled proactive triggers, evaluated client-side.
        $triggers = is_array($settings['triggers'] ?? null) ? $settings['triggers'] : [];
        $session['triggers'] = array_values(array_filter(
            $triggers,
            fn (mixed $trigger): bool => is_array($trigger) && ($trigger['enabled'] ?? false) === true,
        ));

        // Phase 26: org-managed appearance + SERVER-authoritative branding.
        // Branding removal is a paid entitlement (white_label) — the embed
        // page cannot turn it off, only this flag can.
        $appearance = is_array($settings['appearance'] ?? null) ? $settings['appearance'] : [];
        // An uploaded logo file (Phase 30) wins over a pasted URL; the public
        // URL is built from THIS request's host so it works on any origin.
        $logoUrl = is_string($appearance['logo_file'] ?? null) && $appearance['logo_file'] !== ''
            ? url("/api/organizations/{$organization->id}/logo")
            : (is_string($appearance['logo_url'] ?? null) ? $appearance['logo_url'] : null);
        $session['appearance'] = [
            'preset' => is_string($appearance['preset'] ?? null) ? $appearance['preset'] : 'gradient',
            'accent' => is_string($appearance['accent'] ?? null) ? $appearance['accent'] : null,
            'logo_url' => $logoUrl,
            'title' => is_string($appearance['title'] ?? null) ? $appearance['title'] : null,
            'subtitle' => is_string($appearance['subtitle'] ?? null) ? $appearance['subtitle'] : null,
        ];
        $session['show_branding'] = ! $organization->white_label;

        return response()->json($session, 201);
    }
}
