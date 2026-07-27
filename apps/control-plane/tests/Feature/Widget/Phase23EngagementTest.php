<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Visitor;
use App\Services\BusinessHours;
use App\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 23: pre-chat profile capture, CSAT rating, business hours.
 */

/** @return array{0: Organization, 1: string, 2: string} org, visitor id, widget token */
function p23Session(array $settings = []): array
{
    $organization = Organization::factory()->create(['settings' => $settings]);

    $response = test()->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
        'consent_state' => 'granted',
    ])->assertCreated();

    return [$organization, $response->json('visitor_id'), $response->json('token')];
}

function p23AgentToken(Organization $org): string
{
    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'agent@p23.test',
            'password' => Hash::make('password'),
        ]);
    });

    return test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@p23.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');
}

// ── Session bootstrap advertises widget behavior config ─────────────────────

it('advertises prechat config and open state on session bootstrap', function (): void {
    $org = Organization::factory()->create([
        'settings' => ['prechat' => ['enabled' => true, 'require_email' => true]],
    ]);

    $response = test()->postJson('/api/widget/session', [
        'site_key' => $org->widget_site_key,
    ])->assertCreated();

    expect($response->json('prechat.enabled'))->toBeTrue()
        ->and($response->json('prechat.require_email'))->toBeTrue()
        ->and($response->json('open'))->toBeTrue(); // no business hours ⇒ always open
});

// ── Pre-chat profile ────────────────────────────────────────────────────────

it('captures the pre-chat profile: names the visitor and links a lead contact', function (): void {
    [$org, $visitorId, $token] = p23Session();

    $response = test()->withToken($token)->postJson('/api/widget/profile', [
        'name' => 'Ada Lovelace',
        'email' => 'Ada@Example.COM',
    ])->assertOk();

    expect($response->json('display_name'))->toBe('Ada Lovelace')
        ->and($response->json('contact_id'))->toBeString();

    app(Tenancy::class)->run($org->id, function () use ($visitorId): void {
        $visitor = Visitor::query()->findOrFail($visitorId);
        expect($visitor->display_name)->toBe('Ada Lovelace');

        $contact = Contact::query()->findOrFail($visitor->contact_id);
        expect($contact->email)->toBe('ada@example.com'); // normalized
    });
});

it('reuses an existing contact for the same email instead of duplicating', function (): void {
    [$org, , $token] = p23Session();

    $existingId = app(Tenancy::class)->run($org->id, function () use ($org): string {
        return Contact::query()->create([
            'organization_id' => $org->id,
            'email' => 'repeat@example.com',
            'name' => 'Repeat Customer',
        ])->id;
    });

    test()->withToken($token)->postJson('/api/widget/profile', [
        'name' => 'Repeat Customer', 'email' => 'repeat@example.com',
    ])->assertOk()->assertJsonPath('contact_id', $existingId);

    app(Tenancy::class)->run($org->id, function (): void {
        expect(Contact::query()->where('email', 'repeat@example.com')->count())->toBe(1);
    });
});

it('rejects a pre-chat profile without a name', function (): void {
    [, , $token] = p23Session();

    test()->withToken($token)->postJson('/api/widget/profile', ['email' => 'x@example.com'])
        ->assertUnprocessable();
});

// ── CSAT rating ─────────────────────────────────────────────────────────────

/** @return array{0: Organization, 1: string, 2: string} org, widget token, conversation id */
function p23ClosedConversation(): array
{
    [$org, , $token] = p23Session();

    $conversationId = test()->withToken($token)
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    app(Tenancy::class)->run($org->id, function () use ($conversationId): void {
        Conversation::query()->whereKey($conversationId)
            ->update(['status' => 'closed', 'closed_at' => now()]);
    });

    return [$org, $token, $conversationId];
}

it('records a CSAT rating with comment on a closed conversation', function (): void {
    [$org, $token, $conversationId] = p23ClosedConversation();

    test()->withToken($token)
        ->postJson("/api/widget/conversations/{$conversationId}/rating", [
            'rating' => 5, 'comment' => 'Fast and friendly!',
        ])->assertCreated()->assertJsonPath('csat_rating', 5);

    app(Tenancy::class)->run($org->id, function () use ($conversationId): void {
        $conversation = Conversation::query()->findOrFail($conversationId);
        expect($conversation->csat_rating)->toBe(5)
            ->and($conversation->csat_comment)->toBe('Fast and friendly!')
            ->and($conversation->csat_rated_at)->not->toBeNull();
    });
});

it('rejects rating an open conversation with 409', function (): void {
    [, , $token] = p23Session();
    $conversationId = test()->withToken($token)
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    test()->withToken($token)
        ->postJson("/api/widget/conversations/{$conversationId}/rating", ['rating' => 4])
        ->assertConflict();
});

it('rejects out-of-range ratings', function (): void {
    [, $token, $conversationId] = p23ClosedConversation();

    test()->withToken($token)
        ->postJson("/api/widget/conversations/{$conversationId}/rating", ['rating' => 6])
        ->assertUnprocessable();
});

it('blocks rating another visitor\'s conversation (404, no existence leak)', function (): void {
    [, , $conversationId] = p23ClosedConversation();
    [, , $otherToken] = p23Session(); // different org + visitor entirely

    test()->withToken($otherToken)
        ->postJson("/api/widget/conversations/{$conversationId}/rating", ['rating' => 1])
        ->assertNotFound();
});

it('exposes csat on the agent conversation contract and messages poll status', function (): void {
    [$org, $token, $conversationId] = p23ClosedConversation();

    test()->withToken($token)
        ->postJson("/api/widget/conversations/{$conversationId}/rating", ['rating' => 3])
        ->assertCreated();

    // Widget poll reports closure so the widget can prompt for CSAT.
    test()->withToken($token)
        ->getJson("/api/widget/conversations/{$conversationId}/messages")
        ->assertOk()->assertJsonPath('status', 'closed');

    $agentToken = p23AgentToken($org);
    test()->withToken($agentToken)
        ->getJson("/api/conversations/{$conversationId}")
        ->assertOk()->assertJsonPath('csat_rating', 3);
});

// ── Business hours ──────────────────────────────────────────────────────────

it('evaluates business hours in the configured timezone', function (): void {
    $organization = Organization::factory()->create([
        'settings' => [
            'business_hours' => [
                'enabled' => true,
                'timezone' => 'America/New_York',
                'schedule' => [
                    'mon' => [['09:00', '17:00']],
                    'tue' => [['09:00', '17:00']],
                    'wed' => [['09:00', '12:00'], ['13:00', '17:00']],
                    'thu' => [['09:00', '17:00']],
                    'fri' => [['09:00', '17:00']],
                    'sat' => [],
                    'sun' => [],
                ],
            ],
        ],
    ]);

    $hours = app(BusinessHours::class);

    // Wed 10:00 New York (in range) — 14:00 UTC in July (EDT).
    expect($hours->isOpen($organization, CarbonImmutable::parse('2026-07-22T14:00:00Z')))->toBeTrue()
        // Wed 12:30 New York — lunch gap.
        ->and($hours->isOpen($organization, CarbonImmutable::parse('2026-07-22T16:30:00Z')))->toBeFalse()
        // Sunday — closed all day.
        ->and($hours->isOpen($organization, CarbonImmutable::parse('2026-07-26T15:00:00Z')))->toBeFalse();
});

it('fails open when business hours are absent or disabled', function (): void {
    $none = Organization::factory()->create(['settings' => []]);
    $disabled = Organization::factory()->create([
        'settings' => ['business_hours' => ['enabled' => false, 'schedule' => ['mon' => []]]],
    ]);

    $hours = app(BusinessHours::class);
    expect($hours->isOpen($none))->toBeTrue()
        ->and($hours->isOpen($disabled))->toBeTrue();
});

it('reports closed on the session bootstrap when outside hours', function (): void {
    $organization = Organization::factory()->create([
        'settings' => [
            'business_hours' => [
                'enabled' => true,
                'timezone' => 'UTC',
                // Open only during an impossible zero-length window - always closed.
                'schedule' => array_fill_keys(BusinessHours::DAY_KEYS, []),
            ],
        ],
    ]);

    test()->postJson('/api/widget/session', ['site_key' => $organization->widget_site_key])
        ->assertCreated()->assertJsonPath('open', false);
});

// ── Agent settings endpoint ─────────────────────────────────────────────────

it('lets an agent configure prechat and business hours, then round-trips them', function (): void {
    [$org] = p23Session();
    $agentToken = p23AgentToken($org);

    test()->withToken($agentToken)->putJson('/api/organization/widget-settings', [
        'prechat' => ['enabled' => true, 'require_email' => false],
        'business_hours' => [
            'enabled' => true,
            'timezone' => 'Europe/London',
            'schedule' => ['mon' => [['08:30', '18:00']], 'sat' => [], 'sun' => []],
        ],
    ])->assertOk()
        ->assertJsonPath('prechat.enabled', true)
        ->assertJsonPath('business_hours.timezone', 'Europe/London')
        ->assertJsonPath('business_hours.schedule.mon.0.0', '08:30');

    // The widget session sees the new prechat config immediately.
    test()->postJson('/api/widget/session', ['site_key' => $org->fresh()?->widget_site_key])
        ->assertCreated()->assertJsonPath('prechat.enabled', true);
});

it('rejects malformed schedules and unknown timezones', function (): void {
    [$org] = p23Session();
    $agentToken = p23AgentToken($org);

    test()->withToken($agentToken)->putJson('/api/organization/widget-settings', [
        'business_hours' => ['enabled' => true, 'timezone' => 'Mars/Olympus'],
    ])->assertUnprocessable();

    test()->withToken($agentToken)->putJson('/api/organization/widget-settings', [
        'business_hours' => [
            'enabled' => true, 'timezone' => 'UTC',
            'schedule' => ['mon' => [['25:00', '17:00']]],
        ],
    ])->assertUnprocessable();
});
