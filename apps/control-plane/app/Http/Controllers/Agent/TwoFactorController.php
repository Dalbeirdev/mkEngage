<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Self-service two-factor auth (TOTP) for the signed-in agent.
 *
 * Enrollment is two-step: enroll() mints a secret (pending, unconfirmed) and
 * returns the QR/URI; confirm() only flips 2FA on once the user proves they
 * can generate a valid code — so a lost/mis-scanned secret never locks anyone
 * out. Recovery codes are shown exactly once, at confirmation.
 */
final class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /** Begin enrollment: generate a pending secret and return the QR + URI. */
    public function enroll(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_if($user->two_factor_confirmed_at !== null, 409, 'Two-factor is already enabled.');

        $secret = $this->twoFactor->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
        ])->save();

        $uri = $this->twoFactor->otpauthUri($user, $secret);

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_svg' => $this->twoFactor->qrSvg($uri),
        ]);
    }

    /** Confirm enrollment with a valid code; returns the one-time recovery codes. */
    public function confirm(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:10'],
        ]);

        $secret = $user->two_factor_secret;
        abort_if(! is_string($secret) || $secret === '', 409, 'Start enrollment first.');

        if (! $this->twoFactor->verify($secret, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'That code is not valid. Check your authenticator app and try again.',
            ]);
        }

        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        AuditLogEntry::record(actor: 'user:'.$user->id, action: 'two_factor.enabled', subject: $user, ip: $request->ip());

        return response()->json(['recovery_codes' => $recoveryCodes], 201);
    }

    /** Regenerate recovery codes (password-gated); invalidates the old set. */
    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_if($user->two_factor_confirmed_at === null, 409, 'Two-factor is not enabled.');
        $this->assertPassword($request, $user);

        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $recoveryCodes])->save();

        AuditLogEntry::record(actor: 'user:'.$user->id, action: 'two_factor.recovery_regenerated', subject: $user, ip: $request->ip());

        return response()->json(['recovery_codes' => $recoveryCodes]);
    }

    /** Disable 2FA (password-gated), clearing the secret and recovery codes. */
    public function disable(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->assertPassword($request, $user);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        AuditLogEntry::record(actor: 'user:'.$user->id, action: 'two_factor.disabled', subject: $user, ip: $request->ip());

        return response()->json(['status' => 'ok']);
    }

    private function assertPassword(Request $request, User $user): void
    {
        $validated = $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($validated['password'], (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'Your password is incorrect.']);
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
