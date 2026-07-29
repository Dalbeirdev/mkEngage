<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-serve signup: creates a new organization, its owner user, and an Owner
 * role, then issues a session token so the browser is logged straight in.
 *
 * Public + rate-limited by IP. The organization slug is generated (users pick
 * a display name, not a slug) so signups never collide on a chosen handle.
 */
final class RegisterController extends Controller
{
    public function __invoke(Request $request, Tenancy $tenancy): JsonResponse
    {
        $throttleKey = 'register:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 5)) {
            throw ValidationException::withMessages([
                'email' => ['Too many signups from this network. Try again later.'],
            ])->status(429);
        }
        RateLimiter::hit($throttleKey, decaySeconds: 3600);

        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'min:2', 'max:100'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:1024'],
        ]);

        // organizations is not RLS-scoped (it defines tenancy). Slug is
        // generated with a random suffix so it's always unique.
        $organization = Organization::query()->create([
            'name' => $validated['organization_name'],
            'slug' => Str::slug($validated['organization_name']).'-'.Str::lower(Str::random(6)),
            'region' => 'us',
            'white_label' => false,
            'settings' => [],
            'widget_site_key' => 'sk_'.Str::lower(Str::random(24)),
            'widget_signing_secret' => 'whsec_'.Str::random(32),
        ]);

        $token = $tenancy->run($organization->id, function () use ($organization, $validated): string {
            $user = User::query()->create([
                'organization_id' => $organization->id,
                'name' => trim($validated['name']),
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'status' => 'active',
            ]);

            $role = Role::query()->create([
                'organization_id' => $organization->id,
                'name' => 'Owner',
                'description' => 'Full access to the workspace.',
                'is_system' => true,
            ]);
            $user->roles()->attach($role->id, [
                'id' => (string) Str::uuid7(),
                'organization_id' => $organization->id,
            ]);

            AuditLogEntry::record(
                actor: 'user:'.$user->id,
                action: 'organization.created',
                subject: $organization,
                ip: request()->ip(),
            );

            $accessToken = $user->createToken('dashboard');
            $accessToken->accessToken->forceFill(['organization_id' => $organization->id])->save();

            return $accessToken->plainTextToken;
        });

        RateLimiter::clear($throttleKey);

        return response()->json(['token' => $token], 201);
    }
}
