<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 30: org logo upload → widget avatar.
 */

/** @return array{0: Organization, 1: string} */
function p30Org(): array
{
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@p30.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@p30.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

it('uploads a logo, serves it publicly, and the widget session points at it', function (): void {
    Storage::fake('local');
    [$org, $token] = p30Org();

    $upload = test()->withToken($token)->post('/api/organization/logo', [
        'logo' => UploadedFile::fake()->image('brand.png', 128, 128),
    ], ['Accept' => 'application/json'])->assertCreated();

    expect($upload->json('logo_url'))->toContain("/api/organizations/{$org->id}/logo");

    // Public route streams it (no auth).
    $this->get("/api/organizations/{$org->id}/logo")->assertOk();

    // The widget session emits the uploaded logo as the avatar.
    test()->postJson('/api/widget/session', ['site_key' => $org->fresh()?->widget_site_key])
        ->assertCreated()
        ->assertJsonPath('appearance.logo_url', fn (mixed $url): bool => is_string($url) && str_contains($url, "/organizations/{$org->id}/logo"));
});

it('replaces the previous logo file on re-upload and removes it on delete', function (): void {
    Storage::fake('local');
    [$org, $token] = p30Org();

    test()->withToken($token)->post('/api/organization/logo', [
        'logo' => UploadedFile::fake()->image('one.png'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $first = app(Tenancy::class)->run($org->id, function () use ($org): string {
        $settings = $org->fresh()?->settings;

        return is_array($settings) ? (string) ($settings['appearance']['logo_file'] ?? '') : '';
    });
    Storage::disk('local')->assertExists($first);

    test()->withToken($token)->post('/api/organization/logo', [
        'logo' => UploadedFile::fake()->image('two.png'),
    ], ['Accept' => 'application/json'])->assertCreated();
    Storage::disk('local')->assertMissing($first);

    test()->withToken($token)->deleteJson('/api/organization/logo')->assertNoContent();
    $this->get("/api/organizations/{$org->id}/logo")->assertNotFound();
});

it('rejects non-images and oversized files', function (): void {
    Storage::fake('local');
    [, $token] = p30Org();

    test()->withToken($token)->post('/api/organization/logo', [
        'logo' => UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    test()->withToken($token)->post('/api/organization/logo', [
        'logo' => UploadedFile::fake()->image('huge.png')->size(2048),
    ], ['Accept' => 'application/json'])->assertUnprocessable();
});
