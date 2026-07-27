<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Phase 28: emoji reactions (toggle/replace) + quoted replies.
 */

/** @return array{0: Organization, 1: string, 2: string} org, widget token, conversation id */
function p28Fixture(): array
{
    $organization = Organization::factory()->create();

    $token = test()->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->assertCreated()->json('token');

    $conversationId = test()->withToken($token)
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    return [$organization, $token, $conversationId];
}

function p28Send(string $token, string $conversationId, string $body, ?string $replyTo = null): array
{
    return test()->withToken($token)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => $body,
        ...($replyTo !== null ? ['reply_to_message_id' => $replyTo] : []),
    ])->assertCreated()->json();
}

function p28AgentToken(Organization $org): string
{
    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@p28.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    return test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@p28.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');
}

// ── Quoted replies ──────────────────────────────────────────────────────────

it('sends a quoted reply and the contract carries the excerpt', function (): void {
    [, $token, $conversationId] = p28Fixture();

    $original = p28Send($token, $conversationId, 'Original question about pricing');
    $reply = p28Send($token, $conversationId, 'Following up on this', $original['message_id']);

    expect($reply['reply_to']['message_id'])->toBe($original['message_id'])
        ->and($reply['reply_to']['body'])->toBe('Original question about pricing')
        ->and($reply['reply_to']['sender_type'])->toBe('visitor');

    // And it survives the list endpoint (eager-loaded).
    $list = test()->withToken($token)
        ->getJson("/api/widget/conversations/{$conversationId}/messages")
        ->assertOk()->json('data');
    expect(end($list)['reply_to']['body'])->toBe('Original question about pricing');
});

it('rejects quoting a message from another conversation', function (): void {
    [, $token, $conversationId] = p28Fixture();
    $foreign = p28Send($token, $conversationId, 'in conversation A');

    // Second conversation for the SAME visitor.
    $otherConversation = test()->withToken($token)
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    test()->withToken($token)->postJson("/api/widget/conversations/{$otherConversation}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'cross-conversation quote',
        'reply_to_message_id' => $foreign['message_id'],
    ])->assertUnprocessable();
});

// ── Reactions ───────────────────────────────────────────────────────────────

it('toggles and replaces reactions, one per reactor', function (): void {
    [, $token, $conversationId] = p28Fixture();
    $message = p28Send($token, $conversationId, 'react to me');

    $react = fn (string $emoji) => test()->withToken($token)
        ->postJson("/api/widget/conversations/{$conversationId}/messages/{$message['message_id']}/reaction", [
            'emoji' => $emoji,
        ])->assertOk()->json('reactions');

    // Add
    expect($react('👍'))->toBe([['emoji' => '👍', 'count' => 1]]);
    // Replace (different emoji, same reactor — still one reaction)
    expect($react('❤️'))->toBe([['emoji' => '❤️', 'count' => 1]]);
    // Toggle off (same emoji again)
    expect($react('❤️'))->toBe([]);
});

it('aggregates reactions from visitor and agent, and serves the poll map', function (): void {
    [$org, $token, $conversationId] = p28Fixture();
    $message = p28Send($token, $conversationId, 'popular message');

    test()->withToken($token)
        ->postJson("/api/widget/conversations/{$conversationId}/messages/{$message['message_id']}/reaction", ['emoji' => '👍'])
        ->assertOk();

    $agentToken = p28AgentToken($org);
    $summary = test()->withToken($agentToken)
        ->postJson("/api/conversations/{$conversationId}/messages/{$message['message_id']}/reaction", ['emoji' => '👍'])
        ->assertOk()->json('reactions');

    expect($summary)->toBe([['emoji' => '👍', 'count' => 2]]);

    // The widget poll envelope carries the summary for already-seen messages.
    $poll = test()->withToken($token)
        ->getJson("/api/widget/conversations/{$conversationId}/messages?after_sequence=99")
        ->assertOk()->json();
    expect($poll['reactions'][$message['message_id']])->toBe([['emoji' => '👍', 'count' => 2]]);
});

it('blocks reacting to another conversation\'s message (404)', function (): void {
    [, $token, $conversationId] = p28Fixture();
    $message = p28Send($token, $conversationId, 'mine');

    [, $otherToken, $otherConversation] = p28Fixture(); // different org entirely

    test()->withToken($otherToken)
        ->postJson("/api/widget/conversations/{$otherConversation}/messages/{$message['message_id']}/reaction", ['emoji' => '👍'])
        ->assertNotFound();
});
