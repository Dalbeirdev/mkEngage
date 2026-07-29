<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Ticket priority on conversations: defaults to normal, is set via PATCH, and
 * filters the inbox. Validated and tenant-scoped like the rest of the surface.
 */

/** @return array{0: Organization, 1: string} [org, agent token] */
function priOrg(): array
{
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@pri.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@pri.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

/** Create a widget conversation; returns its id. */
function priConversation(Organization $org): string
{
    auth()->forgetGuards();
    $widgetToken = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated()->json('token');

    $conversationId = test()->withToken($widgetToken)
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    test()->withToken($widgetToken)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Hello',
    ])->assertCreated();

    return $conversationId;
}

it('defaults a new conversation to normal priority', function (): void {
    [$org, $token] = priOrg();
    $id = priConversation($org);

    test()->withToken($token)->getJson("/api/conversations/{$id}")
        ->assertOk()->assertJsonPath('priority', 'normal');
});

it('sets priority via PATCH', function (): void {
    [$org, $token] = priOrg();
    $id = priConversation($org);

    test()->withToken($token)->patchJson("/api/conversations/{$id}", ['priority' => 'urgent'])
        ->assertOk()->assertJsonPath('priority', 'urgent');
});

it('rejects an invalid priority', function (): void {
    [$org, $token] = priOrg();
    $id = priConversation($org);

    test()->withToken($token)->patchJson("/api/conversations/{$id}", ['priority' => 'critical'])
        ->assertStatus(422);
});

it('filters the inbox by priority', function (): void {
    [$org, $token] = priOrg();
    $urgent = priConversation($org);
    $normal = priConversation($org);

    test()->withToken($token)->patchJson("/api/conversations/{$urgent}", ['priority' => 'urgent'])->assertOk();

    $results = test()->withToken($token)->getJson('/api/conversations?priority=urgent')
        ->assertOk()->json('data');

    expect($results)->toHaveCount(1)
        ->and($results[0]['conversation_id'])->toBe($urgent);

    // The normal one is excluded.
    $ids = array_column(
        test()->withToken($token)->getJson('/api/conversations?priority=normal')->json('data'),
        'conversation_id',
    );
    expect($ids)->toContain($normal)->not->toContain($urgent);
});
