<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Spam handling: marked-spam conversations drop out of the default inbox and
 * only surface in the Spam view. Tenant-scoped like the rest of the surface.
 */

/** @return array{0: Organization, 1: string} [org, agent token] */
function spamOrg(): array
{
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@spam.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@spam.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

/** Create a widget conversation; returns its id. */
function spamConversation(Organization $org): string
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
        'body' => 'Buy now!!!',
    ])->assertCreated();

    return $conversationId;
}

it('defaults a conversation to not spam', function (): void {
    [$org, $token] = spamOrg();
    $id = spamConversation($org);

    test()->withToken($token)->getJson("/api/conversations/{$id}")
        ->assertOk()->assertJsonPath('is_spam', false);
});

it('marking spam removes it from the default inbox and shows it in the spam view', function (): void {
    [$org, $token] = spamOrg();
    $spam = spamConversation($org);
    $normal = spamConversation($org);

    test()->withToken($token)->patchJson("/api/conversations/{$spam}", ['is_spam' => true])
        ->assertOk()->assertJsonPath('is_spam', true);

    // Default inbox excludes spam.
    $defaultIds = array_column(
        test()->withToken($token)->getJson('/api/conversations')->assertOk()->json('data'),
        'conversation_id',
    );
    expect($defaultIds)->toContain($normal)->not->toContain($spam);

    // Spam view shows only spam.
    $spamIds = array_column(
        test()->withToken($token)->getJson('/api/conversations?spam=only')->assertOk()->json('data'),
        'conversation_id',
    );
    expect($spamIds)->toContain($spam)->not->toContain($normal);
});

it('unmarking spam restores it to the default inbox', function (): void {
    [$org, $token] = spamOrg();
    $id = spamConversation($org);

    test()->withToken($token)->patchJson("/api/conversations/{$id}", ['is_spam' => true])->assertOk();
    test()->withToken($token)->patchJson("/api/conversations/{$id}", ['is_spam' => false])->assertOk();

    $ids = array_column(
        test()->withToken($token)->getJson('/api/conversations')->json('data'),
        'conversation_id',
    );
    expect($ids)->toContain($id);
});
