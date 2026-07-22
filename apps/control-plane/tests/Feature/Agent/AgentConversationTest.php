<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Str;

/** Bootstrap an org with one agent token and one visitor-created conversation with a message. */
function agentFixture(): array
{
    $organization = Organization::factory()->create();

    app(Tenancy::class)->run($organization->id, function (): void {
        User::factory()->create(['email' => 'agent@fixture.test']);
    });

    $agentToken = test()->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => 'agent@fixture.test',
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertCreated()->json('token');

    $visitorToken = test()->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->assertCreated()->json('token');

    $conversationId = test()->withToken($visitorToken)
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    test()->withToken($visitorToken)
        ->postJson("/api/widget/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'Visitor question',
        ])->assertCreated();

    return [$organization, $agentToken, $visitorToken, $conversationId];
}

it('lists conversations for agents with visitor context', function (): void {
    [, $agentToken, , $conversationId] = agentFixture();

    $list = $this->withToken($agentToken)->getJson('/api/conversations')->assertOk();

    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.conversation_id'))->toBe($conversationId)
        ->and($list->json('data.0.last_sequence'))->toBe(1);
});

it('lets agents read history and reply with the same ordering mechanics', function (): void {
    [, $agentToken, $visitorToken, $conversationId] = agentFixture();

    $reply = $this->withToken($agentToken)
        ->postJson("/api/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'Agent answer',
        ])->assertCreated();

    expect($reply->json('sequence_number'))->toBe(2)
        ->and($reply->json('sender_type'))->toBe('agent');

    // Visitor sees the agent reply through the widget API.
    $visitorView = $this->withToken($visitorToken)
        ->getJson("/api/widget/conversations/{$conversationId}/messages?after_sequence=1")
        ->assertOk();

    expect($visitorView->json('data.0.body'))->toBe('Agent answer')
        ->and($visitorView->json('data.0.sender_type'))->toBe('agent');
});

it('duplicate agent replies return the original (idempotency)', function (): void {
    [, $agentToken, , $conversationId] = agentFixture();
    $key = (string) Str::uuid7();

    $first = $this->withToken($agentToken)
        ->postJson("/api/conversations/{$conversationId}/messages", [
            'idempotency_key' => $key,
            'content_type' => 'text',
            'body' => 'Once',
        ])->assertCreated();

    $retry = $this->withToken($agentToken)
        ->postJson("/api/conversations/{$conversationId}/messages", [
            'idempotency_key' => $key,
            'content_type' => 'text',
            'body' => 'Once',
        ])->assertOk();

    expect($retry->json('message_id'))->toBe($first->json('message_id'));
});

it('supports closing and rejects replies to closed conversations', function (): void {
    [, $agentToken, , $conversationId] = agentFixture();

    $this->withToken($agentToken)
        ->patchJson("/api/conversations/{$conversationId}", ['status' => 'closed'])
        ->assertOk();

    $this->withToken($agentToken)
        ->postJson("/api/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'Too late',
        ])->assertStatus(409);
});

it('keeps agent surface tenant-scoped (cross-org 404)', function (): void {
    [, , , $conversationId] = agentFixture();

    // A second organization's agent must not see the first org's conversation.
    $other = Organization::factory()->create();
    app(Tenancy::class)->run($other->id, function (): void {
        User::factory()->create(['email' => 'other@fixture.test']);
    });
    $otherToken = $this->postJson('/api/auth/token', [
        'organization' => $other->slug,
        'email' => 'other@fixture.test',
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertCreated()->json('token');

    $this->withToken($otherToken)
        ->getJson("/api/conversations/{$conversationId}")
        ->assertNotFound();

    expect($this->withToken($otherToken)->getJson('/api/conversations')->json('data'))
        ->toHaveCount(0);
});

it('blocks visitor tokens from the agent surface', function (): void {
    [, , $visitorToken, $conversationId] = agentFixture();

    $this->withToken($visitorToken)->getJson('/api/conversations')->assertStatus(403);
    $this->withToken($visitorToken)
        ->postJson("/api/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'nope',
        ])->assertStatus(403);
});
