<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\TwoFactorService;
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
    public function __invoke(Request $request, Tenancy $tenancy, TwoFactorService $twoFactor): JsonResponse
    {
        $validated = $request->validate([
            'organization' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['required', 'string', 'max:100'],
            // Second factor (only for 2FA-enabled accounts; either one).
            'code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'recovery_code' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        $throttleKey = 'issue-token:'.hash('sha256', $request->ip().'|'.$validated['organization'].'|'.$validated['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 5)) {
            throw ValidationException::withMessages([
                'email' => ['Too many attempts. Try again later.'],
            ])->status(429);
        }

        RateLimiter::hit($throttleKey, decaySeconds: 60);

        $organization = Organization::query()->where('slug', $validated['organization'])->first();

        /** @var array{status: string, token?: string} $result */
        $result = $organization === null
            ? ['status' => 'invalid']
            : $tenancy->run($organization->id, function () use ($organization, $validated, $twoFactor): array {
                $user = User::query()->where('email', $validated['email'])->first();

                if ($user === null
                    || $user->status !== 'active'
                    || ! Hash::check($validated['password'], $user->password)) {
                    return ['status' => 'invalid'];
                }

                // Second factor: only challenged once the password is correct
                // (revealing 2FA-required after a valid password is expected —
                // the attacker already knew the password; they still can't in).
                if ($user->two_factor_confirmed_at !== null) {
                    $second = $this->verifySecondFactor($user, $validated, $twoFactor);
                    if ($second !== 'ok') {
                        return ['status' => $second]; // 'challenge' | 'challenge_invalid'
                    }
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

                return ['status' => 'ok', 'token' => $token->plainTextToken];
            });

        if ($result['status'] === 'challenge' || $result['status'] === 'challenge_invalid') {
            // Password was right but the second factor is missing/wrong — do NOT
            // clear the throttle, so code guessing stays rate-limited.
            return response()->json([
                'two_factor_required' => true,
                'message' => $result['status'] === 'challenge_invalid'
                    ? 'That authentication code is not valid.'
                    : 'Enter the code from your authenticator app.',
            ], 422);
        }

        if ($result['status'] !== 'ok' || ! isset($result['token'])) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        RateLimiter::clear($throttleKey);

        return response()->json(['token' => $result['token']], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return string 'ok' | 'challenge' | 'challenge_invalid'
     */
    private function verifySecondFactor(User $user, array $validated, TwoFactorService $twoFactor): string
    {
        $code = is_string($validated['code'] ?? null) && $validated['code'] !== '' ? $validated['code'] : null;
        $recovery = is_string($validated['recovery_code'] ?? null) && $validated['recovery_code'] !== ''
            ? $validated['recovery_code']
            : null;

        if ($code === null && $recovery === null) {
            return 'challenge';
        }

        $secret = is_string($user->two_factor_secret) ? $user->two_factor_secret : '';
        if ($code !== null && $twoFactor->verify($secret, $code)) {
            return 'ok';
        }

        if ($recovery !== null) {
            /** @var list<string> $codes */
            $codes = is_array($user->two_factor_recovery_codes) ? $user->two_factor_recovery_codes : [];
            $remaining = array_values(array_filter($codes, fn (string $stored): bool => ! hash_equals($stored, $recovery)));
            if (count($remaining) < count($codes)) {
                // Single-use: consume the matched code.
                $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();

                return 'ok';
            }
        }

        return 'challenge_invalid';
    }
}
