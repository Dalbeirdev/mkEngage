<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in agent's own profile: account details, active-session count,
 * a real activity feed from the audit log, plus self-service name edit and
 * password change. All scoped to the authenticated user.
 */
final class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return response()->json($this->payload($user));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:255'],
        ]);

        $user->forceFill(['name' => trim($validated['name'])])->save();

        AuditLogEntry::record(
            actor: 'user:'.$user->id,
            action: 'profile.updated',
            subject: $user,
            ip: $request->ip(),
        );

        return response()->json($this->payload($user->fresh() ?? $user));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'max:1024', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Your current password is incorrect.',
            ]);
        }

        $user->forceFill(['password' => Hash::make($validated['new_password'])])->save();

        AuditLogEntry::record(
            actor: 'user:'.$user->id,
            action: 'password.changed',
            subject: $user,
            ip: $request->ip(),
        );

        return response()->json(['status' => 'ok']);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        $activity = AuditLogEntry::query()
            ->where('actor', 'user:'.$user->id)
            ->latest('created_at')
            ->limit(8)
            ->get(['action', 'created_at'])
            // created_at has a datetime cast, so Laravel serializes it to ISO
            // 8601 in the JSON response automatically.
            ->map(fn (AuditLogEntry $entry): array => [
                'action' => $entry->action,
                'at' => $entry->created_at,
            ])
            ->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'organization_id' => $user->organization_id,
            'status' => $user->status,
            'role' => $user->roles()->first()?->name,
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'active_sessions' => $user->tokens()->count(),
            'created_at' => $user->created_at,
            'activity' => $activity,
        ];
    }
}
