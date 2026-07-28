<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;

/** Agent self-profile: show (role/sessions/activity), edit name, change password. */

/** @return array{0: Organization, 1: string} */
function profileAgent(): array
{
    $org = Organization::factory()->create();
    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'name' => 'Old Name',
            'email' => 'agent@profile.test', 'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();
    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@profile.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

it('returns the profile with an active-session count and activity feed', function (): void {
    [, $token] = profileAgent();

    $body = test()->withToken($token)->getJson('/api/profile')->assertOk()->json();

    expect($body['name'])->toBe('Old Name')
        ->and($body['active_sessions'])->toBeGreaterThanOrEqual(1)
        ->and($body['activity'])->toBeArray();
});

it('updates the display name and records it in the activity feed', function (): void {
    [, $token] = profileAgent();

    test()->withToken($token)->patchJson('/api/profile', ['name' => 'New Name'])
        ->assertOk()->assertJsonPath('name', 'New Name');

    $body = test()->withToken($token)->getJson('/api/profile')->assertOk()->json();
    expect($body['name'])->toBe('New Name')
        ->and(collect($body['activity'])->pluck('action'))->toContain('profile.updated');
});

it('changes the password only with the correct current password', function (): void {
    [, $token] = profileAgent();

    test()->withToken($token)->postJson('/api/profile/password', [
        'current_password' => 'wrong', 'new_password' => 'newsecret123', 'new_password_confirmation' => 'newsecret123',
    ])->assertStatus(422);

    test()->withToken($token)->postJson('/api/profile/password', [
        'current_password' => 'password', 'new_password' => 'newsecret123', 'new_password_confirmation' => 'newsecret123',
    ])->assertOk();
});
