<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Tenancy\Tenancy;
use Illuminate\Support\Str;

/**
 * TOTP two-factor: enrollment is two-step (confirm proves the secret works),
 * login is gated once enabled, and recovery codes are single-use. The second
 * factor is only ever challenged after the password is already correct.
 */

/** @return array{0: Organization, 1: string, 2: string} [org, email, agent token] */
function twoFactorUser(): array
{
    $organization = Organization::factory()->create();
    $email = Str::lower(Str::random(8)).'@agent.test';

    app(Tenancy::class)->run($organization->id, function () use ($email): void {
        User::factory()->create(['email' => $email]);
    });

    $token = test()->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => $email,
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$organization, $email, $token];
}

/** Enroll + confirm 2FA for the user behind $token; returns [secret, recoveryCodes]. */
function enableTwoFactor(Organization $organization, string $token): array
{
    $enroll = test()->withToken($token)->postJson('/api/profile/2fa/enroll')->assertOk();
    $secret = $enroll->json('secret');
    expect($enroll->json('qr_svg'))->toContain('<svg')
        ->and($enroll->json('otpauth_uri'))->toStartWith('otpauth://totp/');

    $code = app(TwoFactorService::class)->currentCode($secret);
    $recovery = test()->withToken($token)
        ->postJson('/api/profile/2fa/confirm', ['code' => $code])
        ->assertCreated()
        ->json('recovery_codes');

    expect($recovery)->toHaveCount(8);

    return [$secret, $recovery];
}

it('enrolls and confirms 2FA, then reflects it on the profile', function (): void {
    [$organization, , $token] = twoFactorUser();

    expect(test()->withToken($token)->getJson('/api/profile')->json('two_factor_enabled'))->toBeFalse();

    enableTwoFactor($organization, $token);

    expect(test()->withToken($token)->getJson('/api/profile')->json('two_factor_enabled'))->toBeTrue();
});

it('rejects confirmation with a wrong code', function (): void {
    [, , $token] = twoFactorUser();
    test()->withToken($token)->postJson('/api/profile/2fa/enroll')->assertOk();

    test()->withToken($token)
        ->postJson('/api/profile/2fa/confirm', ['code' => '000000'])
        ->assertStatus(422);
});

it('challenges login once 2FA is enabled and accepts a valid TOTP code', function (): void {
    [$organization, $email, $token] = twoFactorUser();
    [$secret] = enableTwoFactor($organization, $token);

    // Password alone is no longer enough — the server asks for a code.
    test()->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => $email,
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertStatus(422)->assertJsonPath('two_factor_required', true);

    // With a valid code, a token is issued.
    test()->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => $email,
        'password' => 'password',
        'device_name' => 'pest',
        'code' => app(TwoFactorService::class)->currentCode($secret),
    ])->assertCreated()->assertJsonStructure(['token']);
});

it('accepts a recovery code exactly once', function (): void {
    [$organization, $email, $token] = twoFactorUser();
    [, $recovery] = enableTwoFactor($organization, $token);
    $oneCode = $recovery[0];

    $login = fn (array $extra) => test()->postJson('/api/auth/token', array_merge([
        'organization' => $organization->slug,
        'email' => $email,
        'password' => 'password',
        'device_name' => 'pest',
    ], $extra));

    // First use works.
    $login(['recovery_code' => $oneCode])->assertCreated();
    // Second use of the same code is rejected (single-use).
    $login(['recovery_code' => $oneCode])->assertStatus(422)->assertJsonPath('two_factor_required', true);
});

it('rejects an invalid second factor without issuing a token', function (): void {
    [$organization, $email, $token] = twoFactorUser();
    enableTwoFactor($organization, $token);

    test()->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => $email,
        'password' => 'password',
        'device_name' => 'pest',
        'code' => '123456',
    ])->assertStatus(422)->assertJsonPath('two_factor_required', true);
});

it('disables 2FA with the correct password and restores single-factor login', function (): void {
    [$organization, $email, $token] = twoFactorUser();
    enableTwoFactor($organization, $token);

    // Wrong password cannot disable.
    test()->withToken($token)->deleteJson('/api/profile/2fa', ['password' => 'nope'])->assertStatus(422);

    test()->withToken($token)->deleteJson('/api/profile/2fa', ['password' => 'password'])->assertOk();

    // Password alone logs in again.
    test()->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => $email,
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertCreated();
});

it('does not challenge accounts without 2FA', function (): void {
    [$organization, $email] = twoFactorUser();

    test()->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => $email,
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertCreated()->assertJsonStructure(['token']);
});
