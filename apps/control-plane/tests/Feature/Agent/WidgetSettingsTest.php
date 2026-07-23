<?php

declare(strict_types=1);

use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Str;

function settingsToken(): array
{
    $organization = Organization::factory()->create();
    $email = Str::lower(Str::random(8)).'@admin.test';

    app(Tenancy::class)->run($organization->id, function () use ($email): void {
        User::factory()->create(['email' => $email]);
    });

    $token = test()->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => $email,
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$organization, $token];
}

it('shows the site key and signing status but never the secret', function (): void {
    [$organization, $token] = settingsToken();

    $response = $this->withToken($token)->getJson('/api/organization/widget-settings')->assertOk();

    expect($response->json('site_key'))->toBe($organization->widget_site_key)
        ->and($response->json('signing_configured'))->toBeTrue()
        ->and($response->json())->not->toHaveKey('signing_secret')
        ->and($response->json())->not->toHaveKey('widget_signing_secret');
});

it('rotates the signing secret: old signatures die, new ones verify', function (): void {
    [$organization, $token] = settingsToken();
    $oldSecret = (string) $organization->widget_signing_secret;

    $rotated = $this->withToken($token)
        ->postJson('/api/organization/widget-settings/rotate-secret')
        ->assertCreated();

    $newSecret = $rotated->json('signing_secret');
    expect($newSecret)->toStartWith('whsec_')->not->toBe($oldSecret);

    // Old signature now fails, new one verifies (fail closed on rotation).
    $visitorToken = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->assertCreated()->json('token');

    $this->withToken($visitorToken)->postJson('/api/widget/identify', [
        'external_id' => 'cust-5',
        'signature' => hash_hmac('sha256', 'cust-5', $oldSecret),
    ])->assertStatus(422);

    $this->withToken($visitorToken)->postJson('/api/widget/identify', [
        'external_id' => 'cust-5',
        'signature' => hash_hmac('sha256', 'cust-5', $newSecret),
    ])->assertOk();
});

it('writes an audit entry on rotation', function (): void {
    [$organization, $token] = settingsToken();

    $this->withToken($token)->postJson('/api/organization/widget-settings/rotate-secret')
        ->assertCreated();

    app(Tenancy::class)->run($organization->id, function (): void {
        expect(AuditLogEntry::query()
            ->where('action', 'widget.signing_secret.rotated')
            ->exists())->toBeTrue();
    });
});

it('blocks visitor tokens from widget settings', function (): void {
    [$organization] = settingsToken();

    $visitorToken = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->assertCreated()->json('token');

    $this->withToken($visitorToken)->getJson('/api/organization/widget-settings')->assertStatus(403);
    $this->withToken($visitorToken)
        ->postJson('/api/organization/widget-settings/rotate-secret')->assertStatus(403);
});
