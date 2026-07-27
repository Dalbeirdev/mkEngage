<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\Visitor;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 24: visitor heartbeat/live board, agent-initiated (proactive)
 * conversations, and trigger configuration.
 */

/** @return array{0: Organization, 1: string, 2: string} org, visitor id, widget token */
function p24Session(string $consent = 'granted'): array
{
    $organization = Organization::factory()->create();

    $response = test()->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
        'consent_state' => $consent,
    ])->assertCreated();

    return [$organization, $response->json('visitor_id'), $response->json('token')];
}

function p24AgentToken(Organization $org): string
{
    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'agent@p24.test',
            'password' => Hash::make('password'),
        ]);
    });

    return test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@p24.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');
}

// ── Heartbeat ───────────────────────────────────────────────────────────────

it('heartbeat refreshes presence and stores page context under granted consent', function (): void {
    [$org, $visitorId, $token] = p24Session('granted');

    test()->withToken($token)->postJson('/api/widget/heartbeat', [
        'url' => 'https://example.com/pricing',
        'title' => 'Pricing — Example',
    ])->assertOk()->assertJsonPath('conversation_id', null);

    app(Tenancy::class)->run($org->id, function () use ($visitorId): void {
        $visitor = Visitor::query()->findOrFail($visitorId);
        expect($visitor->current_url)->toBe('https://example.com/pricing')
            ->and($visitor->page_title)->toBe('Pricing — Example')
            ->and($visitor->last_seen_at)->not->toBeNull();
    });
});

it('heartbeat never stores page context without granted consent', function (): void {
    [$org, $visitorId, $token] = p24Session('denied');

    test()->withToken($token)->postJson('/api/widget/heartbeat', [
        'url' => 'https://example.com/secret-page',
        'title' => 'Secret',
    ])->assertOk();

    app(Tenancy::class)->run($org->id, function () use ($visitorId): void {
        $visitor = Visitor::query()->findOrFail($visitorId);
        expect($visitor->current_url)->toBeNull()
            ->and($visitor->page_title)->toBeNull()
            ->and($visitor->last_seen_at)->not->toBeNull(); // still counts as online
    });
});

// ── Live visitor board ──────────────────────────────────────────────────────

it('lists live visitors with page context and hides stale ones', function (): void {
    [$org, $visitorId, $token] = p24Session('granted');

    test()->withToken($token)->postJson('/api/widget/heartbeat', [
        'url' => 'https://example.com/docs', 'title' => 'Docs',
    ])->assertOk();

    // A stale visitor (heartbeat 5 minutes ago) must not appear.
    app(Tenancy::class)->run($org->id, function () use ($org): void {
        Visitor::query()->create([
            'organization_id' => $org->id,
            'consent_state' => 'granted',
            'last_seen_at' => now()->subMinutes(5),
        ]);
    });

    $agentToken = p24AgentToken($org);
    $list = test()->withToken($agentToken)->getJson('/api/visitors/live')->assertOk()->json('data');

    expect($list)->toHaveCount(1)
        ->and($list[0]['visitor_id'])->toBe($visitorId)
        ->and($list[0]['current_url'])->toBe('https://example.com/docs')
        ->and($list[0]['conversation_id'])->toBeNull();
});

it('never leaks another organization\'s live visitors', function (): void {
    [, , $tokenA] = p24Session('granted');
    test()->withToken($tokenA)->postJson('/api/widget/heartbeat', [])->assertOk();

    [$orgB] = p24Session('granted');
    $agentTokenB = p24AgentToken($orgB);

    // Org B's agent sees only org B's visitor (the session bootstrap made one).
    $list = test()->withToken($agentTokenB)->getJson('/api/visitors/live')->assertOk()->json('data');
    foreach ($list as $row) {
        app(Tenancy::class)->run($orgB->id, function () use ($row): void {
            expect(Visitor::query()->whereKey($row['visitor_id'])->exists())->toBeTrue();
        });
    }
});

// ── Agent-initiated (proactive) conversations ───────────────────────────────

it('lets an agent start a conversation which the widget adopts via heartbeat', function (): void {
    [$org, $visitorId, $token] = p24Session('granted');
    $agentToken = p24AgentToken($org);

    $created = test()->withToken($agentToken)->postJson('/api/conversations', [
        'visitor_id' => $visitorId,
        'message' => 'Hi! I noticed you browsing our pricing — can I help?',
    ])->assertCreated();

    $conversationId = $created->json('conversation_id');
    expect($created->json('assigned_agent_name'))->not->toBeNull();

    // The widget's next heartbeat hands it the new conversation.
    test()->withToken($token)->postJson('/api/widget/heartbeat', [])
        ->assertOk()
        ->assertJsonPath('conversation_id', $conversationId);

    // And the visitor can read the agent's opening message.
    $messages = test()->withToken($token)
        ->getJson("/api/widget/conversations/{$conversationId}/messages")
        ->assertOk()->json('data');
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['sender_type'])->toBe('agent')
        ->and($messages[0]['body'])->toContain('noticed you browsing');
});

it('reuses the visitor\'s existing open conversation instead of stacking a new one', function (): void {
    [$org, $visitorId, $token] = p24Session('granted');

    $existing = test()->withToken($token)->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    $agentToken = p24AgentToken($org);
    test()->withToken($agentToken)->postJson('/api/conversations', [
        'visitor_id' => $visitorId, 'message' => 'Following up here.',
    ])->assertCreated()->assertJsonPath('conversation_id', $existing);

    app(Tenancy::class)->run($org->id, function () use ($visitorId): void {
        expect(Conversation::query()->where('visitor_id', $visitorId)->count())->toBe(1);
    });
});

it('blocks starting a conversation with another organization\'s visitor (404)', function (): void {
    [, $foreignVisitorId] = p24Session('granted');
    [$orgB] = p24Session('granted');
    $agentTokenB = p24AgentToken($orgB);

    test()->withToken($agentTokenB)->postJson('/api/conversations', [
        'visitor_id' => $foreignVisitorId, 'message' => 'cross-tenant probe',
    ])->assertNotFound();
});

// ── Trigger configuration ───────────────────────────────────────────────────

it('round-trips proactive triggers and serves only enabled ones to the widget', function (): void {
    [$org] = p24Session();
    $agentToken = p24AgentToken($org);

    test()->withToken($agentToken)->putJson('/api/organization/widget-settings', [
        'triggers' => [
            ['id' => 't-welcome', 'enabled' => true, 'type' => 'time_on_page', 'seconds' => 10,
                'message' => 'Need any help deciding?'],
            ['id' => 't-pricing', 'enabled' => false, 'type' => 'url_match', 'url_pattern' => '/pricing',
                'message' => 'Questions about pricing?'],
        ],
    ])->assertOk()->assertJsonCount(2, 'triggers');

    $session = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated();

    expect($session->json('triggers'))->toHaveCount(1)
        ->and($session->json('triggers.0.id'))->toBe('t-welcome');
});

it('rejects malformed triggers', function (): void {
    [$org] = p24Session();
    $agentToken = p24AgentToken($org);

    // time_on_page without seconds
    test()->withToken($agentToken)->putJson('/api/organization/widget-settings', [
        'triggers' => [['id' => 'x', 'enabled' => true, 'type' => 'time_on_page', 'message' => 'hi']],
    ])->assertUnprocessable();

    // unknown type
    test()->withToken($agentToken)->putJson('/api/organization/widget-settings', [
        'triggers' => [['id' => 'x', 'enabled' => true, 'type' => 'exit_intent', 'message' => 'hi']],
    ])->assertUnprocessable();
});
