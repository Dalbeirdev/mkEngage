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
 * Phase 29: WhatsApp channel — webhook handshake/signature, inbound routing,
 * provider-id dedup, and outbound delivery of agent replies.
 */

/** @return array{0: Organization, 1: Channel} */
function p29Channel(): array
{
    $org = Organization::factory()->create();

    $channel = app(Tenancy::class)->run($org->id, fn (): Channel => Channel::query()->create([
        'organization_id' => $org->id,
        'type' => 'whatsapp',
        'name' => 'Main WhatsApp',
        'status' => 'active',
        'config' => [
            'phone_number_id' => '111222333',
            'waba_id' => 'w-1',
            'access_token' => 'EAAG-test-token',
            'app_secret' => 'shhh-app-secret',
        ],
        'webhook_verify_token' => 'verify-me-123',
    ]));

    return [$org, $channel];
}

/** @return array<string, mixed> a Cloud API webhook body with one text message */
function p29Payload(string $wamid = 'wamid.A1', string $from = '15551234567', string $text = 'Hello from WhatsApp'): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'w-1',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Ravi Kumar']]],
                    'messages' => [[
                        'id' => $wamid, 'from' => $from, 'type' => 'text',
                        'timestamp' => '1700000000', 'text' => ['body' => $text],
                    ]],
                ],
            ]],
        ]],
    ];
}

function p29Post(Organization $org, Channel $channel, array $payload, ?string $secret = 'shhh-app-secret')
{
    $raw = json_encode($payload);
    assert(is_string($raw));

    return test()->call(
        'POST',
        "/api/channels/whatsapp/{$org->id}/{$channel->id}",
        server: [
            'HTTP_X_HUB_SIGNATURE_256' => $secret !== null ? 'sha256='.hash_hmac('sha256', $raw, $secret) : 'sha256=bogus',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        content: $raw,
    );
}

it('completes the Meta verify handshake and rejects bad tokens', function (): void {
    [$org, $channel] = p29Channel();

    $this->get("/api/channels/whatsapp/{$org->id}/{$channel->id}?hub_mode=subscribe&hub_verify_token=verify-me-123&hub_challenge=12345")
        ->assertOk()->assertSee('12345');

    $this->get("/api/channels/whatsapp/{$org->id}/{$channel->id}?hub_verify_token=wrong")
        ->assertForbidden();
});

it('rejects webhook posts with a bad signature and unknown channels', function (): void {
    [$org, $channel] = p29Channel();

    p29Post($org, $channel, p29Payload(), secret: null)->assertForbidden();

    // Unknown channel id: 404, no oracle.
    $this->postJson("/api/channels/whatsapp/{$org->id}/".Str::uuid7()->toString(), p29Payload())
        ->assertNotFound();
});

it('routes an inbound WhatsApp message to a contact + channel conversation and triggers the bot', function (): void {
    Http::fake(); // silence any bot/AI egress
    [$org, $channel] = p29Channel();

    p29Post($org, $channel, p29Payload())->assertOk();

    app(Tenancy::class)->run($org->id, function () use ($channel): void {
        $contact = Contact::query()->where('phone', '15551234567')->firstOrFail();
        expect($contact->name)->toBe('Ravi Kumar');

        $conversation = Conversation::query()->where('channel_id', $channel->id)->firstOrFail();
        expect($conversation->external_thread_id)->toBe('15551234567')
            ->and($conversation->contact_id)->toBe($contact->id);

        $message = Message::query()->where('conversation_id', $conversation->id)->firstOrFail();
        expect($message->sender_type)->toBe('contact')
            ->and($message->body)->toBe('Hello from WhatsApp')
            ->and($message->channel_id)->toBe($channel->id);
    });
});

it('deduplicates Meta retries by provider message id and reuses the thread', function (): void {
    Http::fake();
    [$org, $channel] = p29Channel();

    p29Post($org, $channel, p29Payload(wamid: 'wamid.dup'))->assertOk();
    p29Post($org, $channel, p29Payload(wamid: 'wamid.dup'))->assertOk(); // Meta retry
    p29Post($org, $channel, p29Payload(wamid: 'wamid.two', text: 'Second message'))->assertOk();

    app(Tenancy::class)->run($org->id, function () use ($channel): void {
        expect(Conversation::query()->where('channel_id', $channel->id)->count())->toBe(1);
        $conversation = Conversation::query()->where('channel_id', $channel->id)->firstOrFail();
        expect(Message::query()->where('conversation_id', $conversation->id)->count())->toBe(2);
    });
});

it('delivers agent replies back out through the Cloud API', function (): void {
    [$org, $channel] = p29Channel();

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    p29Post($org, $channel, p29Payload())->assertOk();

    // Agent replies in the WhatsApp conversation.
    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@p29.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();
    $agentToken = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@p29.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    $conversationId = app(Tenancy::class)->run(
        $org->id,
        fn (): string => Conversation::query()->where('channel_id', $channel->id)->firstOrFail()->id,
    );

    test()->withToken($agentToken)->postJson("/api/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Namaste! How can we help?',
    ])->assertCreated();

    // The queued job (sync in tests) posted to the Cloud API.
    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/111222333/messages')
            && $request['to'] === '15551234567'
            && $request['text']['body'] === 'Namaste! How can we help?';
    });
});
