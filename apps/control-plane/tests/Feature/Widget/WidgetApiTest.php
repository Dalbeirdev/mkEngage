<?php

declare(strict_types=1);

use App\Models\Organization;

/** Bootstrap a widget session, returning [organization, visitor_id, token]. */
function widgetSession(): array
{
    $organization = Organization::factory()->create();

    $response = test()->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
        'consent_state' => 'granted',
    ])->assertCreated();

    return [$organization, $response->json('visitor_id'), $response->json('token')];
}

function startConversation(string $token): string
{
    return test()->withToken($token)
        ->postJson('/api/widget/conversations', ['source_url' => 'https://example.com/pricing'])
        ->assertCreated()
        ->json('conversation_id');
}

it('bootstraps a visitor session from a public site key', function (): void {
    [, $visitorId, $token] = widgetSession();

    expect($visitorId)->toBeString()->not->toBeEmpty()
        ->and($token)->toBeString()->toContain('|');
});

it('rejects unknown site keys with the same 404 shape', function (): void {
    $this->postJson('/api/widget/session', ['site_key' => 'sk_doesnotexist'])
        ->assertNotFound();
});

it('creates conversations and sends sequence-ordered messages', function (): void {
    [, , $token] = widgetSession();
    $conversationId = startConversation($token);

    $first = $this->withToken($token)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Hello!',
    ])->assertCreated();

    $second = $this->withToken($token)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Anyone there?',
    ])->assertCreated();

    expect($first->json('sequence_number'))->toBe(1)
        ->and($second->json('sequence_number'))->toBe(2)
        ->and($first->json('lifecycle_state'))->toBe('persisted');

    $list = $this->withToken($token)
        ->getJson("/api/widget/conversations/{$conversationId}/messages?after_sequence=0")
        ->assertOk();

    expect($list->json('data.0.body'))->toBe('Hello!')
        ->and($list->json('data.1.body'))->toBe('Anyone there?')
        ->and($list->json('last_sequence'))->toBe(2);
});

it('returns the original message for a duplicate idempotency key', function (): void {
    [, , $token] = widgetSession();
    $conversationId = startConversation($token);
    $key = (string) Str::uuid7();

    $original = $this->withToken($token)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => $key,
        'content_type' => 'text',
        'body' => 'Only once',
    ])->assertCreated();

    $retry = $this->withToken($token)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => $key,
        'content_type' => 'text',
        'body' => 'Only once',
    ])->assertOk();

    expect($retry->json('message_id'))->toBe($original->json('message_id'))
        ->and($retry->json('sequence_number'))->toBe($original->json('sequence_number'));

    $list = $this->withToken($token)
        ->getJson("/api/widget/conversations/{$conversationId}/messages")
        ->assertOk();

    expect($list->json('data'))->toHaveCount(1);
});

it('supports incremental polling via after_sequence', function (): void {
    [, , $token] = widgetSession();
    $conversationId = startConversation($token);

    foreach (['a', 'b', 'c'] as $body) {
        $this->withToken($token)->postJson("/api/widget/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => $body,
        ])->assertCreated();
    }

    $delta = $this->withToken($token)
        ->getJson("/api/widget/conversations/{$conversationId}/messages?after_sequence=2")
        ->assertOk();

    expect($delta->json('data'))->toHaveCount(1)
        ->and($delta->json('data.0.body'))->toBe('c');
});

it('hides other visitors conversations (404, no existence leak)', function (): void {
    [, , $tokenA] = widgetSession();
    $conversationA = startConversation($tokenA);

    // Second visitor in the SAME organization must not see visitor A's conversation.
    [, , $tokenB] = widgetSession();

    $this->withToken($tokenB)
        ->getJson("/api/widget/conversations/{$conversationA}/messages")
        ->assertNotFound();

    $this->withToken($tokenB)
        ->postJson("/api/widget/conversations/{$conversationA}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'intrusion',
        ])->assertNotFound();
});

it('blocks widget tokens from user routes and user tokens are unaffected', function (): void {
    [, , $token] = widgetSession();

    // Visitor token must never reach admin/user surface (ability scoping).
    $this->withToken($token)->getJson('/api/user')->assertStatus(403);
});

it('requires auth on all widget conversation routes', function (): void {
    $this->postJson('/api/widget/conversations')->assertUnauthorized();
    $this->getJson('/api/widget/conversations/0198c5a0-1111-7000-8000-000000000001/messages')->assertUnauthorized();
});
