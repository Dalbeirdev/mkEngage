<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Str;

/**
 * Moderation: per-org IP ban list (enforced at widget-session creation) and the
 * profanity filter (masks visitor message text at ingest). Both must be
 * tenant-isolated — one org's controls never touch another's.
 */

/** @return array{0: Organization, 1: string} [organization, agent bearer token] */
function moderationToken(): array
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

it('bans an IP and refuses that IP a widget session', function (): void {
    [$organization, $token] = moderationToken();

    // The test client's request IP is 127.0.0.1 — ban it for this org.
    $this->withToken($token)->postJson('/api/moderation/ip-bans', [
        'ip_address' => '127.0.0.1',
        'reason' => 'spam',
    ])->assertCreated()->assertJsonPath('ip_address', '127.0.0.1');

    $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
        'consent_state' => 'granted',
    ])->assertForbidden();
});

it('isolates ban lists across tenants', function (): void {
    [$orgA, $tokenA] = moderationToken();
    $orgB = Organization::factory()->create();

    $this->withToken($tokenA)->postJson('/api/moderation/ip-bans', [
        'ip_address' => '127.0.0.1',
    ])->assertCreated();

    // Org A's ban must not touch Org B: same IP, different site key → allowed.
    $this->postJson('/api/widget/session', [
        'site_key' => $orgB->widget_site_key,
        'consent_state' => 'granted',
    ])->assertCreated();

    // And lifting the ban restores access for org A.
    $banId = $this->withToken($tokenA)->getJson('/api/moderation')
        ->assertOk()->json('ip_bans.0.ip_ban_id');
    $this->withToken($tokenA)->deleteJson("/api/moderation/ip-bans/{$banId}")->assertNoContent();

    $this->postJson('/api/widget/session', [
        'site_key' => $orgA->widget_site_key,
        'consent_state' => 'granted',
    ])->assertCreated();
});

it('masks configured profanity in visitor messages', function (): void {
    [$organization, $token] = moderationToken();

    $this->withToken($token)->putJson('/api/moderation', [
        'profanity' => ['enabled' => true, 'terms' => ['darn', 'heck']],
    ])->assertOk()->assertJsonPath('profanity.enabled', true);

    // Visitor path: open a session for the SAME org, start a conversation, send.
    $session = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
        'consent_state' => 'granted',
    ])->assertCreated();
    $widgetToken = $session->json('token');

    $conversationId = $this->withToken($widgetToken)
        ->postJson('/api/widget/conversations', ['source_url' => 'https://example.com'])
        ->assertCreated()->json('conversation_id');

    $sent = $this->withToken($widgetToken)->postJson(
        "/api/widget/conversations/{$conversationId}/messages",
        [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'oh Darn this HECK show',
        ],
    )->assertCreated();

    // Whole-word, case-insensitive, masked to the term length.
    expect($sent->json('body'))->toBe('oh **** this **** show');
});

it('leaves messages untouched when the filter is disabled', function (): void {
    [$organization, $token] = moderationToken();

    $this->withToken($token)->putJson('/api/moderation', [
        'profanity' => ['enabled' => false, 'terms' => ['darn']],
    ])->assertOk();

    $session = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
        'consent_state' => 'granted',
    ])->assertCreated();
    $widgetToken = $session->json('token');

    $conversationId = $this->withToken($widgetToken)
        ->postJson('/api/widget/conversations', ['source_url' => 'https://example.com'])
        ->assertCreated()->json('conversation_id');

    $sent = $this->withToken($widgetToken)->postJson(
        "/api/widget/conversations/{$conversationId}/messages",
        [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'oh darn',
        ],
    )->assertCreated();

    expect($sent->json('body'))->toBe('oh darn');
});

it('requires authentication for moderation settings', function (): void {
    $this->getJson('/api/moderation')->assertUnauthorized();
    $this->postJson('/api/moderation/ip-bans', ['ip_address' => '1.2.3.4'])->assertUnauthorized();
});
