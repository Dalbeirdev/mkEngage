<?php

declare(strict_types=1);

use App\Models\CannedResponse;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Models\Visitor;
use App\Services\LeadScorer;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 25: lead scoring, canned responses, conversation tags.
 */

/** @return array{0: Organization, 1: string} org, agent token */
function p25Org(): array
{
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'agent@p25.test',
            'password' => Hash::make('password'),
        ]);
    });

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@p25.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

// ── Lead scoring ────────────────────────────────────────────────────────────

it('scores leads from explainable signals and buckets them', function (): void {
    $org = Organization::factory()->create();
    $scorer = new LeadScorer;

    app(Tenancy::class)->run($org->id, function () use ($org, $scorer): void {
        $cold = Visitor::query()->create([
            'organization_id' => $org->id, 'consent_state' => 'unknown', 'last_seen_at' => now(),
        ]);
        expect($scorer->score($cold, hasConversation: false))->toBe(0)
            ->and($scorer->bucket(0))->toBe('cold');

        $warm = Visitor::query()->create([
            'organization_id' => $org->id, 'consent_state' => 'granted',
            'display_name' => 'Warm Lead', 'last_seen_at' => now(),
        ]);
        // named (+10) + conversation (+25) = 35 ⇒ warm
        expect($scorer->score($warm, hasConversation: true))->toBe(35)
            ->and($scorer->bucket(35))->toBe('warm');

        $contact = Contact::query()->create([
            'organization_id' => $org->id, 'email' => 'hot@lead.test', 'name' => 'Hot Lead',
        ]);
        $hot = Visitor::query()->create([
            'organization_id' => $org->id, 'consent_state' => 'granted',
            'display_name' => 'Hot Lead', 'contact_id' => $contact->id,
            'current_url' => 'https://example.com/pricing',
            'last_seen_at' => now(),
        ]);
        // created_at is guarded — backdate explicitly for the time-on-site signal.
        $hot->created_at = now()->subMinutes(10);
        $hot->save();
        // contact 35 + named 10 + conversation 25 + messages 15 + 5min 10 + page 5 = 100
        expect($scorer->score($hot, hasConversation: true, visitorMessages: 4))->toBe(100)
            ->and($scorer->bucket(100))->toBe('hot');
    });
});

it('exposes lead score and bucket on the live visitor board', function (): void {
    [$org, $token] = p25Org();

    // A live visitor via the real widget path.
    $session = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key, 'consent_state' => 'granted',
    ])->assertCreated();
    test()->withToken($session->json('token'))->postJson('/api/widget/heartbeat', [
        'url' => 'https://example.com/pricing', 'title' => 'Pricing',
    ])->assertOk();

    $row = test()->withToken($token)->getJson('/api/visitors/live')
        ->assertOk()->json('data.0');

    expect($row['lead_score'])->toBeInt()->toBeGreaterThanOrEqual(5) // page context signal
        ->and($row['lead_bucket'])->toBeIn(['cold', 'warm', 'hot']);
});

// ── Canned responses ────────────────────────────────────────────────────────

it('creates, lists, updates, and deletes canned responses', function (): void {
    [, $token] = p25Org();

    $created = test()->withToken($token)->postJson('/api/canned-responses', [
        'title' => 'Greeting', 'shortcut' => 'hi', 'body' => 'Hello! How can I help you today?',
    ])->assertCreated();
    $id = $created->json('canned_response_id');

    test()->withToken($token)->postJson('/api/canned-responses', [
        'title' => 'Refund policy', 'shortcut' => 'refund', 'body' => 'Our refund policy is…',
    ])->assertCreated();

    $list = test()->withToken($token)->getJson('/api/canned-responses')->assertOk()->json('data');
    expect($list)->toHaveCount(2)
        ->and($list[0]['shortcut'])->toBe('hi'); // ordered by shortcut

    test()->withToken($token)->putJson("/api/canned-responses/{$id}", [
        'title' => 'Warm greeting', 'shortcut' => 'hi', 'body' => 'Hey there! 👋',
    ])->assertOk()->assertJsonPath('body', 'Hey there! 👋');

    test()->withToken($token)->deleteJson("/api/canned-responses/{$id}")->assertNoContent();
    expect(test()->withToken($token)->getJson('/api/canned-responses')->json('data'))->toHaveCount(1);
});

it('rejects duplicate shortcuts within an org but allows them across orgs', function (): void {
    [, $tokenA] = p25Org();

    test()->withToken($tokenA)->postJson('/api/canned-responses', [
        'title' => 'One', 'shortcut' => 'dup', 'body' => 'a',
    ])->assertCreated();

    test()->withToken($tokenA)->postJson('/api/canned-responses', [
        'title' => 'Two', 'shortcut' => 'dup', 'body' => 'b',
    ])->assertUnprocessable();

    // Same shortcut in ANOTHER org is fine (per-org uniqueness).
    [, $tokenB] = p25Org2();
    test()->withToken($tokenB)->postJson('/api/canned-responses', [
        'title' => 'Other org', 'shortcut' => 'dup', 'body' => 'c',
    ])->assertCreated();
});

it('keeps canned responses tenant-isolated (two-layer)', function (): void {
    [$orgA, $tokenA] = p25Org();
    test()->withToken($tokenA)->postJson('/api/canned-responses', [
        'title' => 'Secret A', 'shortcut' => 'secret-a', 'body' => 'internal',
    ])->assertCreated();

    [, $tokenB] = p25Org2();
    expect(test()->withToken($tokenB)->getJson('/api/canned-responses')->json('data'))->toHaveCount(0);

    // Cross-org update/delete attempts 404 (no existence leak).
    $id = app(Tenancy::class)->run($orgA->id, fn (): string => CannedResponse::query()->firstOrFail()->id);
    test()->withToken($tokenB)->putJson("/api/canned-responses/{$id}", [
        'title' => 'x', 'shortcut' => 'x', 'body' => 'x',
    ])->assertNotFound();
    test()->withToken($tokenB)->deleteJson("/api/canned-responses/{$id}")->assertNotFound();
});

/** Second org helper (distinct email to avoid auth cache). */
function p25Org2(): array
{
    $org = Organization::factory()->create();
    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'agent-b@p25.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent-b@p25.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

// ── Conversation tags ───────────────────────────────────────────────────────

it('sets, normalizes, and filters by conversation tags', function (): void {
    [$org, $token] = p25Org();

    $session = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated();
    $conversationId = test()->withToken($session->json('token'))
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    // Normalization: trim, dedupe, drop empties.
    $updated = test()->withToken($token)->patchJson("/api/conversations/{$conversationId}", [
        'tags' => [' billing ', 'billing', 'vip', '  '],
    ])->assertOk();
    expect($updated->json('tags'))->toBe(['billing', 'vip']);

    // Filter matches...
    $hits = test()->withToken($token)->getJson('/api/conversations?tag=vip')->assertOk()->json('data');
    expect($hits)->toHaveCount(1)
        ->and($hits[0]['conversation_id'])->toBe($conversationId);

    // ...and misses.
    expect(test()->withToken($token)->getJson('/api/conversations?tag=nope')->json('data'))->toHaveCount(0);
});

it('rejects too many or malformed tags', function (): void {
    [$org, $token] = p25Org();

    $session = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated();
    $conversationId = test()->withToken($session->json('token'))
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    test()->withToken($token)->patchJson("/api/conversations/{$conversationId}", [
        'tags' => array_map(fn (int $i): string => "tag-{$i}", range(1, 11)),
    ])->assertUnprocessable();

    test()->withToken($token)->patchJson("/api/conversations/{$conversationId}", [
        'tags' => ['<script>'],
    ])->assertUnprocessable();
});
