<?php

declare(strict_types=1);

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\ConversationMessenger;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Phase 31: Telegram channel — secret-token webhook, inbound routing,
 * update dedup, outbound sendMessage with reply keyboards, setWebhook
 * self-registration.
 */

/** @return array{0: Organization, 1: Channel} */
function p31Channel(): array
{
    $org = Organization::factory()->create();

    $channel = app(Tenancy::class)->run($org->id, fn (): Channel => Channel::query()->create([
        'organization_id' => $org->id,
        'type' => 'telegram',
        'name' => 'Support Bot',
        'status' => 'active',
        'config' => ['bot_token' => '12345:TEST-BOT-TOKEN'],
        'webhook_verify_token' => 'tg-secret-123',
    ]));

    return [$org, $channel];
}

/** @return array<string, mixed> a Bot API Update with one text message */
function p31Update(int $messageId = 100, int $chatId = 777, string $text = 'Hello from Telegram'): array
{
    return [
        'update_id' => 900000 + $messageId,
        'message' => [
            'message_id' => $messageId,
            'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Sana', 'last_name' => 'Iqbal', 'username' => 'sana_i'],
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'date' => 1700000000,
            'text' => $text,
        ],
    ];
}

function p31Post(Organization $org, Channel $channel, array $update, string $secret = 'tg-secret-123')
{
    return test()->postJson(
        "/api/channels/telegram/{$org->id}/{$channel->id}",
        $update,
        ['X-Telegram-Bot-Api-Secret-Token' => $secret],
    );
}

it('rejects wrong secret tokens and unknown channels', function (): void {
    [$org, $channel] = p31Channel();

    p31Post($org, $channel, p31Update(), secret: 'wrong')->assertForbidden();
    test()->postJson("/api/channels/telegram/{$org->id}/".Str::uuid7()->toString(), p31Update())
        ->assertNotFound();
});

it('routes an inbound Telegram message to a contact + conversation and dedups retries', function (): void {
    Http::fake();
    [$org, $channel] = p31Channel();

    p31Post($org, $channel, p31Update())->assertOk();
    p31Post($org, $channel, p31Update())->assertOk(); // Telegram redelivery
    p31Post($org, $channel, p31Update(messageId: 101, text: 'Second'))->assertOk();

    app(Tenancy::class)->run($org->id, function () use ($channel): void {
        $contact = Contact::query()->where('external_id', 'tg:777')->firstOrFail();
        expect($contact->name)->toBe('Sana Iqbal');

        $conversation = Conversation::query()->where('channel_id', $channel->id)->firstOrFail();
        expect($conversation->external_thread_id)->toBe('777');
        expect(Message::query()->where('conversation_id', $conversation->id)->count())->toBe(2);
    });
});

it('delivers agent replies via sendMessage, and rich menus become reply keyboards', function (): void {
    [$org, $channel] = p31Channel();

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    p31Post($org, $channel, p31Update())->assertOk();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@p31.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();
    $agentToken = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@p31.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    $conversation = app(Tenancy::class)->run(
        $org->id,
        fn (): Conversation => Conversation::query()->where('channel_id', $channel->id)->firstOrFail(),
    );

    // Plain agent reply → sendMessage with text.
    test()->withToken($agentToken)->postJson("/api/conversations/{$conversation->id}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Salaam! How can we help?',
    ])->assertCreated();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/bot12345:TEST-BOT-TOKEN/sendMessage')
        && $request['chat_id'] === '777'
        && $request['text'] === 'Salaam! How can we help?');

    // Rich flow menu → reply keyboard buttons, options NOT duplicated in text.
    app(Tenancy::class)->run($org->id, function () use ($conversation): void {
        app(ConversationMessenger::class)->send(
            conversation: $conversation,
            senderType: 'chatbot',
            senderId: Str::uuid7()->toString(),
            body: (string) json_encode(['text' => 'Pick one:', 'options' => ['Sales', 'Support']]),
            idempotencyKey: Str::uuid7()->toString(),
            contentType: 'rich',
        );
    });

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/sendMessage') || $request['text'] !== 'Pick one:') {
            return false;
        }
        $keyboard = $request['reply_markup']['keyboard'] ?? null;

        return $keyboard === [[['text' => 'Sales']], [['text' => 'Support']]];
    });
});

it('self-registers the webhook with Telegram on channel creation', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);

    [$org] = p31Channel();
    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'admin@p31.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();
    $agentToken = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'admin@p31.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    $created = test()->withToken($agentToken)->postJson('/api/channels', [
        'type' => 'telegram', 'name' => 'New Bot', 'bot_token' => '999:NEW-TOKEN',
    ])->assertCreated();

    expect($created->json('webhook_registered'))->toBeTrue()
        ->and($created->json('webhook_url'))->toContain('/api/channels/telegram/');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/bot999:NEW-TOKEN/setWebhook')
        && is_string($request['url']) && str_contains($request['url'], '/api/channels/telegram/')
        && is_string($request['secret_token']) && $request['secret_token'] !== '');
});
