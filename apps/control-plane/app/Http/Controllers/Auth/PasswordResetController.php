<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\PasswordResetLinks;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Self-serve password reset, org-scoped. Rows in password_reset_tokens are
 * keyed "{organization_id}|{email}" because emails are unique only per
 * organization. Both endpoints never reveal whether an account exists
 * (RULES-tenant-isolation #4): forgot always answers 200, reset answers one
 * indistinguishable "invalid or expired" error.
 */
final class PasswordResetController extends Controller
{
    private const TOKEN_TTL_MINUTES = 60;

    public function forgot(Request $request, Tenancy $tenancy): JsonResponse
    {
        $validated = $request->validate([
            'organization' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $throttleKey = 'forgot-password:'.hash('sha256', $request->ip().'|'.$validated['organization'].'|'.$validated['email']);
        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 3)) {
            throw ValidationException::withMessages([
                'email' => ['Too many attempts. Try again later.'],
            ])->status(429);
        }
        RateLimiter::hit($throttleKey, decaySeconds: 600);

        $organization = Organization::query()->where('slug', $validated['organization'])->first();

        if ($organization !== null) {
            $exists = $tenancy->run($organization->id, fn (): bool => User::query()
                ->where('email', $validated['email'])
                ->where('status', 'active')
                ->exists());

            if ($exists === true) {
                $this->issueAndMailToken($organization, $validated['email']);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    public function reset(Request $request, Tenancy $tenancy): JsonResponse
    {
        $validated = $request->validate([
            'organization' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'min:8', 'max:1024'],
        ]);

        $throttleKey = 'reset-password:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 5)) {
            throw ValidationException::withMessages([
                'token' => ['Too many attempts. Try again later.'],
            ])->status(429);
        }
        RateLimiter::hit($throttleKey, decaySeconds: 600);

        $invalid = ValidationException::withMessages([
            'token' => ['This reset link is invalid or has expired.'],
        ]);

        $organization = Organization::query()->where('slug', $validated['organization'])->first();
        if ($organization === null) {
            throw $invalid;
        }

        $key = $organization->id.'|'.$validated['email'];
        $row = DB::table('password_reset_tokens')->where('email', $key)->first();
        $storedHash = is_object($row) && is_string($row->token ?? null) ? $row->token : null;
        $createdRaw = is_object($row) ? ($row->created_at ?? null) : null;
        $createdAt = is_string($createdRaw) ? Carbon::parse($createdRaw) : null;

        if ($storedHash === null
            || $createdAt === null
            || $createdAt->lt(now()->subMinutes(self::TOKEN_TTL_MINUTES))
            || ! hash_equals($storedHash, hash('sha256', $validated['token']))) {
            throw $invalid;
        }

        $done = $tenancy->run($organization->id, function () use ($validated, $request): bool {
            $user = User::query()
                ->where('email', $validated['email'])
                ->where('status', 'active')
                ->first();
            if ($user === null) {
                return false;
            }

            $user->password = Hash::make($validated['password']);
            $user->save();
            // Revoke every existing session token — the old password may be
            // compromised, and so may sessions created with it.
            $user->tokens()->delete();

            AuditLogEntry::record(
                actor: 'user:'.$user->id,
                action: 'auth.password_reset',
                subject: $user,
                ip: $request->ip(),
            );

            return true;
        });

        if ($done !== true) {
            throw $invalid;
        }

        DB::table('password_reset_tokens')->where('email', $key)->delete();

        return response()->json(['status' => 'ok']);
    }

    private function issueAndMailToken(Organization $organization, string $email): void
    {
        $link = app(PasswordResetLinks::class)->issue($organization, $email);
        $organizationName = $organization->name;

        Mail::raw(
            "Someone requested a password reset for your mkEngage account in \"{$organizationName}\".\n\n"
            ."Reset your password (this link is valid for 60 minutes):\n{$link}\n\n"
            ."If you didn't request this, you can safely ignore this email.",
            function ($message) use ($email): void {
                $message->to($email)->subject('Reset your mkEngage password');
            }
        );
    }
}
