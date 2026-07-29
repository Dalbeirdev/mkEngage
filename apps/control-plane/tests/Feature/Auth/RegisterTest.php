<?php

declare(strict_types=1);

use App\Models\Organization;

/** Self-serve signup: creates an org + owner + Owner role and logs in. */
it('registers a new organization and owner, returning a working token', function (): void {
    $token = test()->postJson('/api/auth/register', [
        'organization_name' => 'Bright Coffee Co',
        'name' => 'Sam Rivera',
        'email' => 'sam@bright.test',
        'password' => 'supersecret',
    ])->assertCreated()->json('token');

    expect($token)->toBeString()->not->toBeEmpty();

    // The token logs straight into the new workspace.
    $profile = test()->withToken($token)->getJson('/api/profile')->assertOk()->json();
    expect($profile['name'])->toBe('Sam Rivera')
        ->and($profile['email'])->toBe('sam@bright.test')
        ->and($profile['role'])->toBe('Owner');

    // The organization exists with a generated slug.
    $org = Organization::query()->where('name', 'Bright Coffee Co')->first();
    expect($org)->not->toBeNull()
        ->and($org->slug)->toStartWith('bright-coffee-co-');
});

it('rejects invalid signups', function (): void {
    test()->postJson('/api/auth/register', [
        'organization_name' => 'X', 'name' => '', 'email' => 'not-an-email', 'password' => 'short',
    ])->assertStatus(422);
});

it('isolates a signed-up workspace from other tenants', function (): void {
    $tokenA = test()->postJson('/api/auth/register', [
        'organization_name' => 'Alpha Inc', 'name' => 'A', 'email' => 'a@alpha.test', 'password' => 'supersecret',
    ])->assertCreated()->json('token');

    // A second signup with the SAME email is fine — it's a different org.
    test()->postJson('/api/auth/register', [
        'organization_name' => 'Beta Inc', 'name' => 'B', 'email' => 'a@alpha.test', 'password' => 'supersecret',
    ])->assertCreated();

    // Token A only ever sees org A's own data (its single owner).
    expect(test()->withToken($tokenA)->getJson('/api/users')->assertOk()->json('data'))
        ->toHaveCount(1);
});
