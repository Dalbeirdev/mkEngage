<?php

declare(strict_types=1);

use App\Jobs\DeliverChannelMessage;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Organization;
use App\Models\User;
use App\Services\ConversationMessenger;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Phase 38: customer emoji reactions made inside Telegram sync back into the
 * inbox. Requires storing the provider message id on outbound sends and
 * opting into message_reaction updates at setWebhook time.
 */

/** @return array{0: Organization, 1: Channel, 2: Conversation} a tracked bot message on thread 777. */
function p38Setup(string $providerMessageId = '555'): array
{
    $org = Organization::factory()->create();

    [$channel, $conversation] = app(Tenancy::class)->run($org->id, function () use ($org, $providerMessageId): array {
        $channel = Channel::query()->create([
            'organization_id' => $org->id, 'type' => 'telegram', 'name' => 'Bot',
            'status' => 'active', 'config' => ['bot_token' => '12345:TOK'],
            'webhook_verify_token' => 'tg-secret-123',
        ]);
        $conversation = Conversation::query()->create([
            'channel_id' => $channel->id, 'external_thread_id' => '777',
        ]);
        // A message we delivered to Telegram — reactions map to it by id.
        $result = app(ConversationMessenger::class)->send(
            conversation: $conversation, senderType: 'chatbot',
            senderId: Str::uuid7()->toString(), body: 'How can we help?',
            idempotencyKey: Str::uuid7()->toString(), channelId: $channel->id,
        );
        $result['message']->forceFill(['provider_message_id' => $providerMessageId])->save();

        return [$channel, $conversation];
    });

    return [$org, $channel, $conversation];
}

/** @return array<string, mixed> a message_reaction update (empty new_reaction = cleared). */
function p38Reaction(int $messageId = 555, ?string $emoji = '❤️'): array
{
    $new = $emoji === null ? [] : [['type' => 'emoji', 'emoji' => $emoji]];

    return [
        'update_id' => 42,
        'message_reaction' => [
            'chat' => ['id' => 777, 'type' => 'private'],
            'message_id' => $messageId,
            'user' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Sana'],
            'date' => 1700000000,
            'old_reaction' => [],
            'new_reaction' => $new,
        ],
    ];
}

function p38Post(Organization $org, Channel $channel, array $update)
{
    return test()->postJson(
        "/api/channels/telegram/{$org->id}/{$channel->id}",
        $update,
        ['X-Telegram-Bot-Api-Secret-Token' => 'tg-secret-123'],
    );
}

it('records a customer emoji reaction from Telegram on the tracked message', function (): void {
    Http::fake();
    [$org, $channel] = p38Setup();

    p38Post($org, $channel, p38Reaction(emoji: '❤️'))->assertOk();

    app(Tenancy::class)->run($org->id, function (): void {
        $reaction = MessageReaction::query()->firstOrFail();
        expect($reaction->reactor_type)->toBe('contact')
            ->and($reaction->emoji)->toBe('❤️');
    });
});

it('replaces then clears the reaction as the customer changes it', function (): void {
    Http::fake();
    [$org, $channel] = p38Setup();

    p38Post($org, $channel, p38Reaction(emoji: '❤️'))->assertOk();
    p38Post($org, $channel, p38Reaction(emoji: '🤪'))->assertOk(); // switched emoji

    app(Tenancy::class)->run($org->id, function (): void {
        expect(MessageReaction::query()->count())->toBe(1)
            ->and(MessageReaction::query()->firstOrFail()->emoji)->toBe('🤪');
    });

    p38Post($org, $channel, p38Reaction(emoji: null))->assertOk(); // removed in Telegram

    app(Tenancy::class)->run($org->id, function (): void {
        expect(MessageReaction::query()->count())->toBe(0);
    });
});

it('ignores reactions on messages it did not send', function (): void {
    Http::fake();
    [$org, $channel] = p38Setup();

    p38Post($org, $channel, p38Reaction(messageId: 999999))->assertOk();

    app(Tenancy::class)->run($org->id, function (): void {
        expect(MessageReaction::query()->count())->toBe(0);
    });
});

it('stores the provider message id when delivering outbound so reactions can map back', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4321]], 200)]);
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        $channel = Channel::query()->create([
            'organization_id' => $org->id, 'type' => 'telegram', 'name' => 'Bot',
            'status' => 'active', 'config' => ['bot_token' => '12345:TOK'],
            'webhook_verify_token' => 'v',
        ]);
        $conversation = Conversation::query()->create([
            'channel_id' => $channel->id, 'external_thread_id' => '777',
        ]);
        $result = app(ConversationMessenger::class)->send(
            conversation: $conversation, senderType: 'agent',
            senderId: Str::uuid7()->toString(), body: 'Hi there',
            idempotencyKey: Str::uuid7()->toString(), channelId: $channel->id,
        );
        DeliverChannelMessage::dispatchSync((string) $org->id, $result['message']->id);

        expect(Message::query()->whereKey($result['message']->id)->firstOrFail()->provider_message_id)
            ->toBe('4321');
    });
});

it('requests message_reaction updates when registering the webhook', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'admin@p38.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();
    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'admin@p38.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    test()->withToken($token)->postJson('/api/channels', [
        'type' => 'telegram', 'name' => 'Bot', 'bot_token' => '999:NEW',
    ])->assertCreated();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/setWebhook')) {
            return false;
        }
        $allowed = is_string($request['allowed_updates'] ?? null)
            ? json_decode($request['allowed_updates'], true)
            : null;

        return is_array($allowed) && in_array('message_reaction', $allowed, true);
    });
});
