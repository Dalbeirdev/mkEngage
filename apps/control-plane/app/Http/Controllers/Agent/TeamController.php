<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\PasswordResetLinks;
use App\Services\PlanService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Team management: invite agents by email (they set their own password via
 * the shared reset-token flow), deactivate/reactivate members. Invites and
 * reactivation are seat-gated by the org's plan.
 *
 * {user} params are resolved manually — implicit binding runs before the
 * tenant context middleware.
 */
final class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => User::query()->orderBy('name')->limit(200)
                ->get(['id', 'name', 'email', 'status'])
                ->map(fn (User $user): array => [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                ])
                ->all(),
        ]);
    }

    public function store(
        Request $request,
        TenantContext $context,
        PlanService $plans,
        PasswordResetLinks $links,
    ): JsonResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        if (User::query()->where('email', $validated['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['This email is already a team member. Use "Resend invite" instead.'],
            ]);
        }

        $organization = $this->organization($context);
        $plans->assertCanCreate($organization, 'agents');

        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => trim($validated['name']),
            'email' => $validated['email'],
            // Unusable until the invitee sets their own via the invite link.
            'password' => Hash::make(bin2hex(random_bytes(24))),
            'status' => 'active',
        ]);

        $this->sendInvite($organization, $user, $links);

        $actor = $request->user();
        AuditLogEntry::record(
            actor: $actor instanceof User ? 'user:'.$actor->id : 'system',
            action: 'team.invited',
            subject: $user,
            ip: $request->ip(),
        );

        return response()->json($this->contract($user), 201);
    }

    public function resend(
        Request $request,
        TenantContext $context,
        PasswordResetLinks $links,
        string $user,
    ): JsonResponse {
        $member = User::query()->whereKey($user)->firstOrFail();
        $this->sendInvite($this->organization($context), $member, $links);

        return response()->json(['status' => 'ok']);
    }

    public function update(
        Request $request,
        TenantContext $context,
        PlanService $plans,
        string $user,
    ): JsonResponse {
        $validated = $request->validate([
            'status' => ['required', 'in:active,disabled'],
        ]);

        $member = User::query()->whereKey($user)->firstOrFail();

        $actor = $request->user();
        if ($actor instanceof User && $actor->id === $member->id) {
            throw ValidationException::withMessages([
                'status' => ['You cannot change your own status.'],
            ]);
        }

        if ($validated['status'] === 'disabled') {
            if ($member->status === 'active'
                && User::query()->where('status', 'active')->count() <= 1) {
                throw ValidationException::withMessages([
                    'status' => ['At least one active team member is required.'],
                ]);
            }
            $member->status = 'disabled';
            $member->save();
            // Kill their sessions immediately — status is only checked at login.
            $member->tokens()->delete();
        } elseif ($member->status !== 'active') {
            $plans->assertCanCreate($this->organization($context), 'agents');
            $member->status = 'active';
            $member->save();
        }

        AuditLogEntry::record(
            actor: $actor instanceof User ? 'user:'.$actor->id : 'system',
            action: 'team.status_changed',
            subject: $member,
            context: ['status' => $member->status],
            ip: $request->ip(),
        );

        return response()->json($this->contract($member));
    }

    private function sendInvite(Organization $organization, User $member, PasswordResetLinks $links): void
    {
        $link = $links->issue($organization, $member->email);
        $organizationName = $organization->name;
        $slug = $organization->slug;
        $email = $member->email;

        Mail::raw(
            "You've been invited to join \"{$organizationName}\" on mkEngage.\n\n"
            ."Set your password to get started (this link is valid for 60 minutes):\n{$link}\n\n"
            ."Afterwards, sign in with organization \"{$slug}\" and this email address.",
            function ($message) use ($email, $organizationName): void {
                $message->to($email)->subject("You're invited to {$organizationName} on mkEngage");
            }
        );
    }

    /** @return array<string, string> */
    private function contract(User $user): array
    {
        return [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
        ];
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->whereKey($context->organizationId())->firstOrFail();
    }
}
