<?php

declare(strict_types=1);

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Instagram DM channel — Meta handshake/signature, IGSID routing with a Graph
 * name/username lookup, mid dedup, and the shared Messenger Send API.
 */

/** @return array{0: Organization, 1: Channel} */
function igChannel(): array
{
    $org = Organization::factory()->create();

    $channel = app(Tenancy::class)->run($org->id, fn (): Channel => Channel::query()->create([
        'organization_id' => $org->id,
        'type' => 'instagram',
        'name' => 'IG Inbox',
        'status' => 'active',
        'config' => [
            'ig_id' => 'ig-77',
            'access_token' => 'EAAB-ig-token',
            'app_secret' => 'ig-app-secret',
        ],
        'webhook_verify_token' => 'ig-verify-999',
    ]));

    return [$org, $channel];
}

/** @return array<string, mixed> */
function igPayload(string $mid = 'mid.1', string $igsid = 'igsid-1001', string $text = 'Hi from Instagram'): array
{
    return [
        'object' => 'instagram',
        'entry' => [[
            'id' => 'ig-77',
            'time' => 1700000000,
            'messaging' => [[
                'sender' => ['id' => $igsid],
                'recipient' => ['id' => 'ig-77'],
                'timestamp' => 1700000000,
                'message' => ['mid' => $mid, 'text' => $text],
            ]],
        ]],
    ];
}

function igPost(Organization $org, Channel $channel, array $payload, ?string $secret = 'ig-app-secret')
{
    $raw = json_encode($payload);
    assert(is_string($raw));

    return test()->call(
        'POST',
        "/api/channels/instagram/{$org->id}/{$channel->id}",
        server: [
            'HTTP_X_HUB_SIGNATURE_256' => $secret !== null ? 'sha256='.hash_hmac('sha256', $raw, $secret) : 'sha256=bogus',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        content: $raw,
    );
}

it('completes the verify handshake and rejects bad signatures', function (): void {
    [$org, $channel] = igChannel();

    $this->get("/api/channels/instagram/{$org->id}/{$channel->id}?hub_verify_token=ig-verify-999&hub_challenge=IG-CHAL")
        ->assertOk()->assertSee('IG-CHAL');
    $this->get("/api/channels/instagram/{$org->id}/{$channel->id}?hub_verify_token=nope")
        ->assertForbidden();
    igPost($org, $channel, igPayload(), secret: null)->assertForbidden();
});

it('routes inbound DMs: IGSID contact with Graph name, thread reuse, mid dedup', function (): void {
    Http::fake([
        'graph.facebook.com/*igsid-1001*' => Http::response(['name' => 'Priya Sharma', 'username' => 'priya'], 200),
        '*' => Http::response([], 200),
    ]);
    [$org, $channel] = igChannel();

    igPost($org, $channel, igPayload())->assertOk();
    igPost($org, $channel, igPayload())->assertOk(); // Meta retry
    igPost($org, $channel, igPayload(mid: 'mid.2', text: 'Second'))->assertOk();

    app(Tenancy::class)->run($org->id, function () use ($channel): void {
        $contact = Contact::query()->where('external_id', 'ig:igsid-1001')->firstOrFail();
        expect($contact->name)->toBe('Priya Sharma');

        $conversation = Conversation::query()->where('channel_id', $channel->id)->firstOrFail();
        expect($conversation->external_thread_id)->toBe('igsid-1001');
        expect(Message::query()->where('conversation_id', $conversation->id)->count())->toBe(2);
    });
});

it('falls back to @username, then a placeholder, when name is absent', function (): void {
    Http::fake([
        'graph.facebook.com/*igsid-2002*' => Http::response(['username' => 'coolhandle'], 200),
        'graph.facebook.com/*' => Http::response(['error' => 'nope'], 400),
    ]);
    [$org, $channel] = igChannel();

    igPost($org, $channel, igPayload(igsid: 'igsid-2002', mid: 'mid.8'))->assertOk();
    igPost($org, $channel, igPayload(igsid: 'igsid-3003', mid: 'mid.9'))->assertOk();

    app(Tenancy::class)->run($org->id, function (): void {
        expect(Contact::query()->where('external_id', 'ig:igsid-2002')->firstOrFail()->name)->toBe('@coolhandle');
        expect(Contact::query()->where('external_id', 'ig:igsid-3003')->firstOrFail()->name)->toBe('Instagram igsid-30');
    });
});

it('delivers replies via the Send API', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid.out'], 200)]);
    [$org, $channel] = igChannel();
    igPost($org, $channel, igPayload())->assertOk();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@ig.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();
    $agentToken = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@ig.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    $conversation = app(Tenancy::class)->run(
        $org->id,
        fn (): Conversation => Conversation::query()->where('channel_id', $channel->id)->firstOrFail(),
    );

    test()->withToken($agentToken)->postJson("/api/conversations/{$conversation->id}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Thanks for the DM!',
    ])->assertCreated();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/me/messages')
        && $request['recipient']['id'] === 'igsid-1001'
        && $request['message']['text'] === 'Thanks for the DM!');
});
