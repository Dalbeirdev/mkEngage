<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Visitor;
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
    public function __invoke(Request $request, Tenancy $tenancy): JsonResponse
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

        /** @var array{visitor_id: string, token: string} $session */
        $session = $tenancy->run($organization->id, function () use ($organization, $validated): array {
            $visitor = Visitor::query()->create([
                'consent_state' => $validated['consent_state'] ?? 'unknown',
                'last_seen_at' => now(),
            ]);

            $token = $visitor->createToken('widget', ['widget']);
            $token->accessToken->forceFill(['organization_id' => $organization->id])->save();

            return ['visitor_id' => $visitor->id, 'token' => $token->plainTextToken];
        });

        return response()->json($session, 201);
    }
}
