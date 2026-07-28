<?php

declare(strict_types=1);

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Services\ConversationMessenger;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Phase 33: inbox search/filters, per-agent read state, WhatsApp
 * interactive buttons.
 */

/** @return array{0: Organization, 1: string} */
function p33Org(): array
{
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@p33.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@p33.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

/** Create a widget conversation with one visitor message; returns its id. */
function p33Conversation(Organization $org, string $messageBody): string
{
    auth()->forgetGuards(); // each helper call is a NEW visitor identity
    $widgetToken = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated()->json('token');

    $conversationId = test()->withToken($widgetToken)
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    test()->withToken($widgetToken)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => $messageBody,
    ])->assertCreated();

    return $conversationId;
}

it('searches conversations by message body and participant name', function (): void {
    [$org, $token] = p33Org();

    $hit = p33Conversation($org, 'My zebra subscription is broken');
    p33Conversation($org, 'Just saying hello');

    $results = test()->withToken($token)->getJson('/api/conversations?search=zebra')
        ->assertOk()->json('data');
    expect($results)->toHaveCount(1)
        ->and($results[0]['conversation_id'])->toBe($hit);

    // Name match: name the visitor via pre-chat, then search it.
    auth()->forgetGuards();
    $widgetToken = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated()->json('token');
    test()->withToken($widgetToken)->postJson('/api/widget/profile', ['name' => 'Zubin Mehta'])->assertOk();
    test()->withToken($widgetToken)->postJson('/api/widget/conversations', [])->assertCreated();

    auth()->forgetGuards(); // switch back to the agent identity
    expect(test()->withToken($token)->getJson('/api/conversations?search=Zubin')->json('data'))
        ->toHaveCount(1);
    expect(test()->withToken($token)->getJson('/api/conversations?search=nomatchxyz')->json('data'))
        ->toHaveCount(0);
});

it('filters by channel including the web pseudo-channel', function (): void {
    Http::fake();
    [$org, $token] = p33Org();

    p33Conversation($org, 'from the widget');

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        $channel = Channel::query()->create([
            'organization_id' => $org->id, 'type' => 'telegram', 'name' => 'TG',
            'status' => 'active', 'config' => ['bot_token' => 'x'],
            'webhook_verify_token' => 'v',
        ]);
        Conversation::query()->create([
            'channel_id' => $channel->id, 'external_thread_id' => '99',
        ]);
    });

    expect(test()->withToken($token)->getJson('/api/conversations?channel=web')->json('data'))->toHaveCount(1)
        ->and(test()->withToken($token)->getJson('/api/conversations?channel=telegram')->json('data'))->toHaveCount(1)
        ->and(test()->withToken($token)->getJson('/api/conversations?channel=whatsapp')->json('data'))->toHaveCount(0);
});

it('tracks per-agent unread counts and clears them on mark-read', function (): void {
    [$org, $token] = p33Org();
    $conversationId = p33Conversation($org, 'first');

    // One visitor message, never read → unread 1.
    $row = test()->withToken($token)->getJson('/api/conversations?limit=5')->json('data.0');
    expect($row['unread_count'])->toBe(1);

    test()->withToken($token)->postJson("/api/conversations/{$conversationId}/read")
        ->assertOk()->assertJsonPath('last_read_sequence', 1);

    $row = test()->withToken($token)->getJson('/api/conversations?limit=5')->json('data.0');
    expect($row['unread_count'])->toBe(0);

    // The read cursor is PER AGENT: a second agent still sees 1 unread.
    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'second@p33.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();
    $secondToken = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'second@p33.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    expect(test()->withToken($secondToken)->getJson('/api/conversations?limit=5')->json('data.0.unread_count'))
        ->toBe(1);
});

it('sends WhatsApp rich menus as interactive buttons (≤3) and text beyond', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w']]], 200)]);
    $org = Organization::factory()->create();

    [$channel, $conversation] = app(Tenancy::class)->run($org->id, function () use ($org): array {
        $channel = Channel::query()->create([
            'organization_id' => $org->id, 'type' => 'whatsapp', 'name' => 'WA',
            'status' => 'active',
            'config' => ['phone_number_id' => 'pn-1', 'access_token' => 't', 'app_secret' => 's'],
            'webhook_verify_token' => 'v',
        ]);
        $conversation = Conversation::query()->create([
            'channel_id' => $channel->id, 'external_thread_id' => '155500',
        ]);

        return [$channel, $conversation];
    });

    $send = function (array $options) use ($org, $conversation): void {
        app(Tenancy::class)->run($org->id, function () use ($conversation, $options): void {
            app(ConversationMessenger::class)->send(
                conversation: $conversation,
                senderType: 'chatbot',
                senderId: Str::uuid7()->toString(),
                body: (string) json_encode(['text' => 'Pick:', 'options' => $options]),
                idempotencyKey: Str::uuid7()->toString(),
                contentType: 'rich',
            );
        });
    };

    $send(['One', 'Two', 'A very long option label that overflows']);
    Http::assertSent(function ($request): bool {
        if (($request['type'] ?? null) !== 'interactive') {
            return false;
        }
        $buttons = $request['interactive']['action']['buttons'] ?? [];

        return count($buttons) === 3
            && $buttons[2]['reply']['title'] === 'A very long option l'
            && $buttons[2]['reply']['id'] === 'A very long option label that overflows';
    });

    $send(['A', 'B', 'C', 'D']); // 4 options → text fallback
    Http::assertSent(fn ($request): bool => ($request['type'] ?? null) === 'text'
        && str_contains((string) $request['text']['body'], '• D'));
});
