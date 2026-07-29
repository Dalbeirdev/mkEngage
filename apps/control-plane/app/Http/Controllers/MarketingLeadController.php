<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContactSubmission;
use App\Models\NewsletterSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Public inbound leads from the marketing site: the contact form and the
 * newsletter opt-in. Both are unauthenticated and IP rate-limited (the site
 * holds no token). No tenant context — these belong to mkEngage, not a
 * customer org.
 */
final class MarketingLeadController extends Controller
{
    public function contact(Request $request): JsonResponse
    {
        if ($this->throttled($request, 'contact', maxAttempts: 10)) {
            return response()->json(['title' => 'Too many messages', 'status' => 429], 429);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:100'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        ContactSubmission::query()->create([
            'name' => $validated['name'],
            'email' => Str::lower($validated['email']),
            'company' => $validated['company'] ?? null,
            'subject' => $validated['subject'] ?? null,
            'message' => $validated['message'],
        ]);

        return response()->json(['status' => 'received'], 201);
    }

    public function subscribe(Request $request): JsonResponse
    {
        if ($this->throttled($request, 'newsletter', maxAttempts: 15)) {
            return response()->json(['title' => 'Too many requests', 'status' => 429], 429);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        // Idempotent: re-subscribing the same address is a no-op success, never
        // a duplicate row or a unique-constraint error.
        NewsletterSubscription::query()->firstOrCreate(
            ['email' => Str::lower($validated['email'])],
            ['source' => $validated['source'] ?? 'website'],
        );

        return response()->json(['status' => 'subscribed'], 201);
    }

    /** Per-IP hourly throttle; returns true once the limit is exceeded. */
    private function throttled(Request $request, string $bucket, int $maxAttempts): bool
    {
        $key = $bucket.':'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return true;
        }
        RateLimiter::hit($key, decaySeconds: 3600);

        return false;
    }
}
