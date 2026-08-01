<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/**
 * Team management: invite → set password via the shared token flow → sign
 * in; seat limits; deactivation kills sessions; self-changes blocked.
 */
function teamOrg(): array
{
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'owner@team.test',
            'name' => 'Team Owner', 'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'owner@team.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

function teamInviteTokenFromMail(): string
{
    /** @var ArrayTransport $transport */
    $transport = Mail::getSymfonyTransport();
    $bodies = collect($transport->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage())
        ->filter(fn ($msg) => $msg instanceof Email)
        ->map(fn (Email $msg) => (string) $msg->getTextBody());

    preg_match('/token=([0-9a-f]{64})/', (string) $bodies->last(), $m);
    expect($m)->toHaveCount(2);

    return $m[1];
}

it('invites an agent who sets a password and signs in', function (): void {
    config()->set('mail.default', 'array');
    [$org, $token] = teamOrg();

    test()->withToken($token)->postJson('/api/users', [
        'name' => 'New Agent', 'email' => 'new-agent@team.test',
    ])->assertCreated()->assertJson(['email' => 'new-agent@team.test', 'status' => 'active']);

    $inviteToken = teamInviteTokenFromMail();

    test()->postJson('/api/auth/reset-password', [
        'organization' => $org->slug, 'email' => 'new-agent@team.test',
        'token' => $inviteToken, 'password' => 'my-chosen-password-7',
    ])->assertOk();

    test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'new-agent@team.test',
        'password' => 'my-chosen-password-7', 'device_name' => 'pest',
    ])->assertCreated();

    // Listed with status for the Team page.
    $emails = collect(test()->withToken($token)->getJson('/api/users')->json('data'))->pluck('email');
    expect($emails)->toContain('new-agent@team.test');
});

it('rejects duplicate invites and enforces the seat limit', function (): void {
    config()->set('mail.default', 'array');
    [, $token] = teamOrg();

    test()->withToken($token)->postJson('/api/users', [
        'name' => 'Owner Again', 'email' => 'owner@team.test',
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    // Free plan seats: 3. Owner + 2 invites fill them; the 4th member fails.
    test()->withToken($token)->postJson('/api/users', ['name' => 'A2', 'email' => 'a2@team.test'])->assertCreated();
    test()->withToken($token)->postJson('/api/users', ['name' => 'A3', 'email' => 'a3@team.test'])->assertCreated();
    test()->withToken($token)->postJson('/api/users', ['name' => 'A4', 'email' => 'a4@team.test'])
        ->assertStatus(422)->assertJsonValidationErrors('plan');
});

it('deactivation revokes sessions and blocks login; reactivation restores access', function (): void {
    config()->set('mail.default', 'array');
    [$org, $token] = teamOrg();

    test()->withToken($token)->postJson('/api/users', [
        'name' => 'Temp Agent', 'email' => 'temp@team.test',
    ])->assertCreated();
    $inviteToken = teamInviteTokenFromMail();
    test()->postJson('/api/auth/reset-password', [
        'organization' => $org->slug, 'email' => 'temp@team.test',
        'token' => $inviteToken, 'password' => 'temp-password-123',
    ])->assertOk();
    $memberToken = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'temp@team.test',
        'password' => 'temp-password-123', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    $memberId = collect(test()->withToken($token)->getJson('/api/users')->json('data'))
        ->firstWhere('email', 'temp@team.test')['user_id'];

    test()->withToken($token)->patchJson("/api/users/{$memberId}", ['status' => 'disabled'])
        ->assertOk()->assertJson(['status' => 'disabled']);

    // Session dead, login blocked.
    test()->withToken($memberToken)->getJson('/api/user')->assertUnauthorized();
    test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'temp@team.test',
        'password' => 'temp-password-123', 'device_name' => 'pest',
    ])->assertStatus(422);

    // Reactivate (seat-gated) restores login.
    test()->withToken($token)->patchJson("/api/users/{$memberId}", ['status' => 'active'])
        ->assertOk()->assertJson(['status' => 'active']);
    test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'temp@team.test',
        'password' => 'temp-password-123', 'device_name' => 'pest',
    ])->assertCreated();
});

it('blocks changing your own status', function (): void {
    [, $token] = teamOrg();

    $selfId = test()->withToken($token)->getJson('/api/user')->json('id');

    test()->withToken($token)->patchJson("/api/users/{$selfId}", ['status' => 'disabled'])
        ->assertStatus(422)->assertJsonValidationErrors('status');
});
