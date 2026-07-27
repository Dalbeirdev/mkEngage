<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 26: org-managed widget appearance + server-authoritative branding.
 */

/** @return array{0: Organization, 1: string} */
function p26Org(bool $whiteLabel = false): array
{
    $org = Organization::factory()->create(['white_label' => $whiteLabel]);

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'agent@p26.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@p26.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

it('round-trips appearance config and serves it on the widget session', function (): void {
    [$org, $token] = p26Org();

    test()->withToken($token)->putJson('/api/organization/widget-settings', [
        'appearance' => [
            'preset' => 'midnight',
            'accent' => '#e11d48',
            'logo_url' => 'https://cdn.example.com/logo.png',
            'title' => 'TechPIO',
            'subtitle' => 'We are online!',
        ],
    ])->assertOk()
        ->assertJsonPath('appearance.preset', 'midnight')
        ->assertJsonPath('appearance.accent', '#e11d48');

    $session = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated();

    expect($session->json('appearance.preset'))->toBe('midnight')
        ->and($session->json('appearance.accent'))->toBe('#e11d48')
        ->and($session->json('appearance.logo_url'))->toBe('https://cdn.example.com/logo.png')
        ->and($session->json('appearance.title'))->toBe('TechPIO');
});

it('defaults to the gradient preset when nothing is configured', function (): void {
    [$org] = p26Org();

    test()->postJson('/api/widget/session', ['site_key' => $org->fresh()?->widget_site_key])
        ->assertCreated()
        ->assertJsonPath('appearance.preset', 'gradient')
        ->assertJsonPath('appearance.accent', null);
});

it('rejects unknown presets, bad hex colors, and bad logo urls', function (): void {
    [, $token] = p26Org();

    test()->withToken($token)->putJson('/api/organization/widget-settings', [
        'appearance' => ['preset' => 'neon'],
    ])->assertUnprocessable();

    test()->withToken($token)->putJson('/api/organization/widget-settings', [
        'appearance' => ['preset' => 'classic', 'accent' => 'red'],
    ])->assertUnprocessable();

    test()->withToken($token)->putJson('/api/organization/widget-settings', [
        'appearance' => ['preset' => 'classic', 'logo_url' => 'javascript:alert(1)'],
    ])->assertUnprocessable();
});

it('shows branding for free orgs and hides it only via the white_label flag', function (): void {
    // Free org: branding on, regardless of anything the embed page claims.
    [$orgFree] = p26Org(whiteLabel: false);
    test()->postJson('/api/widget/session', ['site_key' => $orgFree->fresh()?->widget_site_key])
        ->assertCreated()->assertJsonPath('show_branding', true);

    // Paid (white-label) org: branding off, server-side.
    [$orgPaid] = p26Org(whiteLabel: true);
    test()->postJson('/api/widget/session', ['site_key' => $orgPaid->fresh()?->widget_site_key])
        ->assertCreated()->assertJsonPath('show_branding', false);
});

it('never lets the settings endpoint change the white_label entitlement', function (): void {
    [$org, $token] = p26Org(whiteLabel: false);

    // Attempting to sneak white_label into the payload changes nothing.
    test()->withToken($token)->putJson('/api/organization/widget-settings', [
        'white_label' => true,
        'appearance' => ['preset' => 'classic'],
    ])->assertOk()->assertJsonPath('white_label', false);

    expect($org->fresh()?->white_label)->toBeFalse();
});
