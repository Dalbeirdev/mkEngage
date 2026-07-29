<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Per-agent saved inbox views: create, list (own only), delete. RLS scopes to
 * the org; the controller further narrows to the caller's user.
 */

/** @return array{0: Organization, 1: string, 2: string} [org, email, token] */
function savedViewAgent(?Organization $org = null): array
{
    $org ??= Organization::factory()->create();
    $email = Str::lower(Str::random(8)).'@views.test';

    app(Tenancy::class)->run($org->id, function () use ($email): void {
        User::factory()->create(['email' => $email, 'password' => Hash::make('password')]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => $email,
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $email, $token];
}

it('saves and lists an agent view', function (): void {
    [, , $token] = savedViewAgent();

    test()->withToken($token)->postJson('/api/saved-views', [
        'name' => 'Urgent WhatsApp',
        'filters' => ['tab' => 'open', 'channel' => 'whatsapp', 'priority' => 'urgent', 'search' => ''],
    ])->assertCreated()
        ->assertJsonPath('name', 'Urgent WhatsApp')
        ->assertJsonPath('filters.channel', 'whatsapp')
        // Empty filter values are dropped.
        ->assertJsonMissingPath('filters.search');

    test()->withToken($token)->getJson('/api/saved-views')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Urgent WhatsApp');
});

it('rejects an unknown filter key', function (): void {
    [, , $token] = savedViewAgent();

    test()->withToken($token)->postJson('/api/saved-views', [
        'name' => 'Bad', 'filters' => ['tab' => 'open', 'bogus' => 'x'],
    ])->assertStatus(422);
});

it('deletes a view', function (): void {
    [, , $token] = savedViewAgent();

    $id = test()->withToken($token)->postJson('/api/saved-views', [
        'name' => 'Temp', 'filters' => ['tab' => 'closed'],
    ])->assertCreated()->json('saved_view_id');

    test()->withToken($token)->deleteJson("/api/saved-views/{$id}")->assertNoContent();
    test()->withToken($token)->getJson('/api/saved-views')->assertOk()->assertJsonCount(0, 'data');
});

it('never exposes another agent view (same org)', function (): void {
    [$org, , $tokenA] = savedViewAgent();

    $id = test()->withToken($tokenA)->postJson('/api/saved-views', [
        'name' => 'Mine', 'filters' => ['tab' => 'all'],
    ])->assertCreated()->json('saved_view_id');

    // A second agent in the SAME org sees none of A's views and cannot delete them.
    [, , $tokenB] = savedViewAgent($org);
    test()->withToken($tokenB)->getJson('/api/saved-views')->assertOk()->assertJsonCount(0, 'data');
    test()->withToken($tokenB)->deleteJson("/api/saved-views/{$id}")->assertNotFound();
});
