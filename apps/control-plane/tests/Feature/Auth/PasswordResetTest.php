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
 * Self-serve password reset: org-scoped tokens, no account enumeration,
 * expiry, and tenant isolation (same email in two orgs).
 */
function pwrOrg(string $email): Organization
{
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org, $email): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => $email,
            'name' => 'Reset Target', 'password' => Hash::make('old-password-1'),
        ]);
    });
    auth()->forgetGuards();

    return $org;
}

/** Extract the reset token from the last captured mail. */
function pwrTokenFromMail(): string
{
    /** @var ArrayTransport $transport */
    $transport = Mail::getSymfonyTransport();
    $bodies = collect($transport->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage())
        ->filter(fn ($msg) => $msg instanceof Email)
        ->map(fn (Email $msg) => (string) $msg->getTextBody());

    $last = $bodies->last();
    expect($last)->toBeString();
    preg_match('/token=([0-9a-f]{64})/', (string) $last, $m);
    expect($m)->toHaveCount(2);

    return $m[1];
}

it('resets the password end to end and revokes existing sessions', function (): void {
    config()->set('mail.default', 'array');
    $org = pwrOrg('owner@pwr.test');

    // Existing session that must die with the reset.
    $login = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'owner@pwr.test',
        'password' => 'old-password-1', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    test()->postJson('/api/auth/forgot-password', [
        'organization' => $org->slug, 'email' => 'owner@pwr.test',
    ])->assertOk()->assertJson(['status' => 'ok']);

    $token = pwrTokenFromMail();

    test()->postJson('/api/auth/reset-password', [
        'organization' => $org->slug, 'email' => 'owner@pwr.test',
        'token' => $token, 'password' => 'brand-new-password-9',
    ])->assertOk()->assertJson(['status' => 'ok']);

    // Old password rejected, new one works.
    test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'owner@pwr.test',
        'password' => 'old-password-1', 'device_name' => 'pest',
    ])->assertStatus(422);
    test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'owner@pwr.test',
        'password' => 'brand-new-password-9', 'device_name' => 'pest',
    ])->assertCreated();

    // The pre-reset session token was revoked.
    test()->withToken($login)->getJson('/api/user')->assertUnauthorized();

    // The reset token is single-use.
    test()->postJson('/api/auth/reset-password', [
        'organization' => $org->slug, 'email' => 'owner@pwr.test',
        'token' => $token, 'password' => 'another-password-10',
    ])->assertStatus(422);
});

it('answers 200 without sending mail for unknown orgs and emails', function (): void {
    config()->set('mail.default', 'array');
    $org = pwrOrg('someone@pwr2.test');

    test()->postJson('/api/auth/forgot-password', [
        'organization' => 'no-such-org', 'email' => 'someone@pwr2.test',
    ])->assertOk();
    test()->postJson('/api/auth/forgot-password', [
        'organization' => $org->slug, 'email' => 'stranger@pwr2.test',
    ])->assertOk();

    /** @var ArrayTransport $transport */
    $transport = Mail::getSymfonyTransport();
    expect($transport->messages())->toHaveCount(0);
});

it('rejects wrong and expired tokens', function (): void {
    config()->set('mail.default', 'array');
    $org = pwrOrg('expiry@pwr3.test');

    test()->postJson('/api/auth/forgot-password', [
        'organization' => $org->slug, 'email' => 'expiry@pwr3.test',
    ])->assertOk();
    $token = pwrTokenFromMail();

    test()->postJson('/api/auth/reset-password', [
        'organization' => $org->slug, 'email' => 'expiry@pwr3.test',
        'token' => str_repeat('0', 64), 'password' => 'whatever-password-1',
    ])->assertStatus(422);

    $this->travel(61)->minutes();
    test()->postJson('/api/auth/reset-password', [
        'organization' => $org->slug, 'email' => 'expiry@pwr3.test',
        'token' => $token, 'password' => 'whatever-password-1',
    ])->assertStatus(422);
});

it('scopes tokens to the organization — same email in another org cannot use them', function (): void {
    config()->set('mail.default', 'array');
    $orgA = pwrOrg('shared@pwr4.test');
    $orgB = pwrOrg('shared@pwr4.test');

    test()->postJson('/api/auth/forgot-password', [
        'organization' => $orgA->slug, 'email' => 'shared@pwr4.test',
    ])->assertOk();
    $token = pwrTokenFromMail();

    // Org B with org A's token: rejected, and org B's password unchanged.
    test()->postJson('/api/auth/reset-password', [
        'organization' => $orgB->slug, 'email' => 'shared@pwr4.test',
        'token' => $token, 'password' => 'hijack-attempt-99',
    ])->assertStatus(422);
    test()->postJson('/api/auth/token', [
        'organization' => $orgB->slug, 'email' => 'shared@pwr4.test',
        'password' => 'old-password-1', 'device_name' => 'pest',
    ])->assertCreated();
});
