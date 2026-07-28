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
 * Phase 32: Facebook Messenger channel — Meta handshake/signature, PSID
 * routing with Graph profile lookup, mid dedup, Send API + quick replies.
 */

/** @return array{0: Organization, 1: Channel} */
function p32Channel(): array
{
    $org = Organization::factory()->create();

    $channel = app(Tenancy::class)->run($org->id, fn (): Channel => Channel::query()->create([
        'organization_id' => $org->id,
        'type' => 'messenger',
        'name' => 'Page Inbox',
        'status' => 'active',
        'config' => [
            'page_id' => 'page-42',
            'access_token' => 'EAAB-page-token',
            'app_secret' => 'fb-app-secret',
        ],
        'webhook_verify_token' => 'fb-verify-999',
    ]));

    return [$org, $channel];
}

/** @return array<string, mixed> */
function p32Payload(string $mid = 'mid.1', string $psid = 'psid-1001', string $text = 'Hi from Messenger'): array
{
    return [
        'object' => 'page',
        'entry' => [[
            'id' => 'page-42',
            'time' => 1700000000,
            'messaging' => [[
                'sender' => ['id' => $psid],
                'recipient' => ['id' => 'page-42'],
                'timestamp' => 1700000000,
                'message' => ['mid' => $mid, 'text' => $text],
            ]],
        ]],
    ];
}

function p32Post(Organization $org, Channel $channel, array $payload, ?string $secret = 'fb-app-secret')
{
    $raw = json_encode($payload);
    assert(is_string($raw));

    return test()->call(
        'POST',
        "/api/channels/messenger/{$org->id}/{$channel->id}",
        server: [
            'HTTP_X_HUB_SIGNATURE_256' => $secret !== null ? 'sha256='.hash_hmac('sha256', $raw, $secret) : 'sha256=bogus',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        content: $raw,
    );
}

it('completes the verify handshake and rejects bad signatures', function (): void {
    [$org, $channel] = p32Channel();

    $this->get("/api/channels/messenger/{$org->id}/{$channel->id}?hub_verify_token=fb-verify-999&hub_challenge=FB-CHAL")
        ->assertOk()->assertSee('FB-CHAL');
    $this->get("/api/channels/messenger/{$org->id}/{$channel->id}?hub_verify_token=nope")
        ->assertForbidden();
    p32Post($org, $channel, p32Payload(), secret: null)->assertForbidden();
});

it('routes inbound messages: PSID contact with Graph profile name, thread reuse, mid dedup', function (): void {
    Http::fake([
        'graph.facebook.com/*psid-1001*' => Http::response(['first_name' => 'Meera', 'last_name' => 'Nair'], 200),
        '*' => Http::response([], 200),
    ]);
    [$org, $channel] = p32Channel();

    p32Post($org, $channel, p32Payload())->assertOk();
    p32Post($org, $channel, p32Payload())->assertOk(); // Meta retry
    p32Post($org, $channel, p32Payload(mid: 'mid.2', text: 'Second'))->assertOk();

    app(Tenancy::class)->run($org->id, function () use ($channel): void {
        $contact = Contact::query()->where('external_id', 'fb:psid-1001')->firstOrFail();
        expect($contact->name)->toBe('Meera Nair');

        $conversation = Conversation::query()->where('channel_id', $channel->id)->firstOrFail();
        expect($conversation->external_thread_id)->toBe('psid-1001');
        expect(Message::query()->where('conversation_id', $conversation->id)->count())->toBe(2);
    });
});

it('falls back to a placeholder name when the Graph lookup fails', function (): void {
    Http::fake(['*' => Http::response(['error' => 'nope'], 400)]);
    [$org, $channel] = p32Channel();

    p32Post($org, $channel, p32Payload(psid: 'psid-2002', mid: 'mid.9'))->assertOk();

    app(Tenancy::class)->run($org->id, function (): void {
        expect(Contact::query()->where('external_id', 'fb:psid-2002')->firstOrFail()->name)
            ->toBe('Messenger psid-200');
    });
});

it('delivers replies via the Send API; rich menus become quick replies', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid.out'], 200)]);
    [$org, $channel] = p32Channel();
    p32Post($org, $channel, p32Payload())->assertOk();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@p32.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();
    $agentToken = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@p32.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    $conversation = app(Tenancy::class)->run(
        $org->id,
        fn (): Conversation => Conversation::query()->where('channel_id', $channel->id)->firstOrFail(),
    );

    test()->withToken($agentToken)->postJson("/api/conversations/{$conversation->id}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Hello from the page!',
    ])->assertCreated();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/me/messages')
        && $request['recipient']['id'] === 'psid-1001'
        && $request['message']['text'] === 'Hello from the page!');

    // Rich flow menu → quick_replies with title-capped options.
    app(Tenancy::class)->run($org->id, function () use ($conversation): void {
        app(ConversationMessenger::class)->send(
            conversation: $conversation,
            senderType: 'chatbot',
            senderId: Str::uuid7()->toString(),
            body: (string) json_encode(['text' => 'Choose:', 'options' => ['Sales', 'A very long option label indeed']]),
            idempotencyKey: Str::uuid7()->toString(),
            contentType: 'rich',
        );
    });

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/me/messages') || ($request['message']['text'] ?? null) !== 'Choose:') {
            return false;
        }
        $quick = $request['message']['quick_replies'] ?? [];

        return count($quick) === 2
            && $quick[0]['title'] === 'Sales'
            && $quick[1]['title'] === 'A very long option l'
            && $quick[1]['payload'] === 'A very long option label indeed';
    });
});
