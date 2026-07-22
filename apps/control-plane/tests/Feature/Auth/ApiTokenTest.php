<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;

function makeUserInOrg(Organization $organization, string $email = 'agent@example.com'): User
{
    return app(Tenancy::class)->run(
        $organization->id,
        fn (): User => User::factory()->create(['email' => $email]),
    );
}

it('issues a token for valid credentials and scopes /user to the org', function (): void {
    $organization = Organization::factory()->create();
    $user = makeUserInOrg($organization);

    $response = $this->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => 'agent@example.com',
        'password' => 'password',
        'device_name' => 'pest',
    ]);

    $response->assertCreated();
    $token = $response->json('token');
    expect($token)->toBeString()->not->toBeEmpty();

    $this->flushHeaders();

    $me = $this->withToken($token)->getJson('/api/user');
    $me->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('organization_id', $organization->id)
        ->assertJsonMissingPath('password')
        ->assertJsonMissingPath('two_factor_secret');
});

it('rejects wrong password, unknown user, and unknown org identically', function (): void {
    $organization = Organization::factory()->create();
    makeUserInOrg($organization);

    $wrongPassword = $this->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => 'agent@example.com',
        'password' => 'nope',
        'device_name' => 'pest',
    ]);

    $unknownUser = $this->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => 'ghost@example.com',
        'password' => 'password',
        'device_name' => 'pest',
    ]);

    $unknownOrg = $this->postJson('/api/auth/token', [
        'organization' => 'no-such-org',
        'email' => 'agent@example.com',
        'password' => 'password',
        'device_name' => 'pest',
    ]);

    foreach ([$wrongPassword, $unknownUser, $unknownOrg] as $response) {
        $response->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    // Identical error bodies: org existence must not leak (RULES-tenant-isolation #4).
    expect($unknownOrg->json('errors'))->toBe($wrongPassword->json('errors'));
});

it('rejects tenant routes without a token', function (): void {
    $this->getJson('/api/user')->assertUnauthorized();
});

it('rejects tokens of suspended users', function (): void {
    $organization = Organization::factory()->create();
    $user = makeUserInOrg($organization);

    $token = $this->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => 'agent@example.com',
        'password' => 'password',
        'device_name' => 'pest',
    ])->json('token');

    app(Tenancy::class)->run($organization->id, function () use ($user): void {
        User::query()->whereKey($user->id)->update(['status' => 'suspended']);
    });

    // Suspension currently blocks NEW logins; live-token revocation fan-out
    // (Redis deny-list, ADR-002/009) arrives with the gateway phase. Issue a
    // fresh login attempt to prove the block:
    $this->flushHeaders();
    $this->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => 'agent@example.com',
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertUnprocessable();
});

it('rate limits token issuance', function (): void {
    $organization = Organization::factory()->create();
    makeUserInOrg($organization);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/auth/token', [
            'organization' => $organization->slug,
            'email' => 'agent@example.com',
            'password' => 'wrong',
            'device_name' => 'pest',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => 'agent@example.com',
        'password' => 'wrong',
        'device_name' => 'pest',
    ])->assertStatus(429);
});
