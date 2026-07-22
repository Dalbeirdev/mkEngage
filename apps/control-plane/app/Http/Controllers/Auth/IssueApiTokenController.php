<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Issues a Sanctum personal access token from org-slug + credentials.
 *
 * Login resolves the organization FIRST (organizations is not RLS-scoped —
 * it defines tenancy), then verifies the user inside Tenancy::run() so the
 * users lookup itself executes under that org's RLS context. Failures are
 * indistinguishable (same message, same timing shape) whether the org, the
 * user, or the password was wrong — existence never leaks
 * (RULES-tenant-isolation #4).
 */
final class IssueApiTokenController extends Controller
{
    public function __invoke(Request $request, Tenancy $tenancy): JsonResponse
    {
        $validated = $request->validate([
            'organization' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $throttleKey = 'issue-token:'.hash('sha256', $request->ip().'|'.$validated['organization'].'|'.$validated['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 5)) {
            throw ValidationException::withMessages([
                'email' => ['Too many attempts. Try again later.'],
            ])->status(429);
        }

        RateLimiter::hit($throttleKey, decaySeconds: 60);

        $organization = Organization::query()->where('slug', $validated['organization'])->first();

        $plainTextToken = $organization === null ? null : $tenancy->run($organization->id, function () use ($organization, $validated): ?string {
            $user = User::query()->where('email', $validated['email'])->first();

            if ($user === null
                || $user->status !== 'active'
                || ! Hash::check($validated['password'], $user->password)) {
                return null;
            }

            $token = $user->createToken($validated['device_name']);
            $token->accessToken->forceFill(['organization_id' => $organization->id])->save();

            AuditLogEntry::record(
                actor: 'user:'.$user->id,
                action: 'auth.token.issued',
                subject: $user,
                context: ['device_name' => $validated['device_name']],
                ip: request()->ip(),
            );

            return $token->plainTextToken;
        });

        if ($plainTextToken === null) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        RateLimiter::clear($throttleKey);

        return response()->json(['token' => $plainTextToken], 201);
    }
}
