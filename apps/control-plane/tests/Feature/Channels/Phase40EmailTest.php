<?php

declare(strict_types=1);

use App\Jobs\DeliverChannelMessage;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * Email channel — token-authenticated inbound-parse webhook, address-keyed
 * contact/thread, and outbound replies via the mailer.
 */

/** @return array{0: Organization, 1: Channel} */
function emailChannel(): array
{
    $org = Organization::factory()->create();

    $channel = app(Tenancy::class)->run($org->id, fn (): Channel => Channel::query()->create([
        'organization_id' => $org->id,
        'type' => 'email',
        'name' => 'Support Inbox',
        'status' => 'active',
        'config' => ['from_address' => 'support@acme.test', 'from_name' => 'Acme Support'],
        'webhook_verify_token' => 'email-secret-123',
    ]));

    return [$org, $channel];
}

function emailPost(Organization $org, Channel $channel, array $payload, ?string $token = 'email-secret-123')
{
    $req = test();
    if ($token !== null) {
        $req = $req->withHeaders(['X-Webhook-Token' => $token]);
    }

    return $req->postJson("/api/channels/email/{$org->id}/{$channel->id}", $payload);
}

it('rejects a webhook without the secret token', function (): void {
    [$org, $channel] = emailChannel();

    emailPost($org, $channel, ['from' => 'a@b.test', 'text' => 'hi'], token: null)->assertForbidden();
    emailPost($org, $channel, ['from' => 'a@b.test', 'text' => 'hi'], token: 'wrong')->assertForbidden();
});

it('routes an inbound email to an address-keyed contact and thread', function (): void {
    [$org, $channel] = emailChannel();

    emailPost($org, $channel, [
        'from' => 'Jamie Rivers <Jamie@Example.com>',
        'subject' => 'Refund request',
        'text' => 'I need a refund for order 42.',
        'message_id' => 'msg-1',
    ])->assertOk();

    // Provider retry with the same Message-Id is deduped.
    emailPost($org, $channel, [
        'from' => 'jamie@example.com', 'subject' => 'Refund request',
        'text' => 'I need a refund for order 42.', 'message_id' => 'msg-1',
    ])->assertOk();

    app(Tenancy::class)->run($org->id, function () use ($channel): void {
        $contact = Contact::query()->where('external_id', 'email:jamie@example.com')->firstOrFail();
        expect($contact->email)->toBe('jamie@example.com');

        $conversation = Conversation::query()->where('channel_id', $channel->id)->firstOrFail();
        expect($conversation->external_thread_id)->toBe('jamie@example.com');

        $messages = Message::query()->where('conversation_id', $conversation->id)->get();
        expect($messages)->toHaveCount(1)
            ->and($messages[0]->body)->toContain('Refund request')  // subject rides at top
            ->and($messages[0]->body)->toContain('order 42');
    });
});

it('ignores a payload with no usable sender address', function (): void {
    [$org, $channel] = emailChannel();

    emailPost($org, $channel, ['subject' => 'no sender', 'text' => 'body'])
        ->assertOk()->assertJsonPath('status', 'ignored');

    app(Tenancy::class)->run($org->id, function () use ($channel): void {
        expect(Conversation::query()->where('channel_id', $channel->id)->count())->toBe(0);
    });
});

it('uses the global mailer when the channel has no SMTP of its own', function (): void {
    [$org, $channel] = emailChannel();

    app(Tenancy::class)->run($org->id, function () use ($channel): void {
        expect(DeliverChannelMessage::emailSmtpConfig($channel))->toBeNull();
    });
});

it('builds a per-org SMTP mailer from the channel credentials', function (): void {
    $org = Organization::factory()->create();
    $channel = app(Tenancy::class)->run($org->id, fn (): Channel => Channel::query()->create([
        'organization_id' => $org->id, 'type' => 'email', 'name' => 'Own SMTP', 'status' => 'active',
        'config' => [
            'from_address' => 'help@acme.test',
            'smtp_host' => 'smtp.acme.test', 'smtp_port' => 2525,
            'smtp_username' => 'apikey', 'smtp_password' => 's3cret', 'smtp_encryption' => 'tls',
        ],
        'webhook_verify_token' => 'x',
    ]));

    $cfg = app(Tenancy::class)->run($org->id, fn () => DeliverChannelMessage::emailSmtpConfig($channel));

    // Read back through the encrypted cast, so this also proves the round-trip.
    expect($cfg)->toMatchArray([
        'transport' => 'smtp', 'host' => 'smtp.acme.test', 'port' => 2525,
        'encryption' => 'tls', 'username' => 'apikey', 'password' => 's3cret',
    ]);
});

it('stores per-org SMTP on channel creation and never returns it', function (): void {
    $org = Organization::factory()->create();
    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@smtp.test', 'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();
    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@smtp.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    $id = test()->withToken($token)->postJson('/api/channels', [
        'type' => 'email', 'name' => 'Custom SMTP', 'from_address' => 'support@acme.test',
        'smtp_host' => 'smtp.acme.test', 'smtp_port' => 587,
        'smtp_username' => 'u', 'smtp_password' => 'p', 'smtp_encryption' => 'tls',
    ])->assertCreated()->assertJsonMissingPath('config')->json('channel_id');

    app(Tenancy::class)->run($org->id, function () use ($id): void {
        $channel = Channel::query()->findOrFail($id);
        expect($channel->configString('smtp_host'))->toBe('smtp.acme.test')
            ->and($channel->configString('smtp_password'))->toBe('p');
    });
});

it('threads the reply subject as Re: of the inbound subject', function (): void {
    config()->set('mail.default', 'array');

    [$org, $channel] = emailChannel();
    // Inbound already carries a Re: — the reply must not stack another.
    emailPost($org, $channel, [
        'from' => 'thread@example.com', 'subject' => 'Re: Invoice #42 overdue', 'text' => 'ping',
    ])->assertOk();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@thread.test', 'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();
    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@thread.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    $conversation = app(Tenancy::class)->run(
        $org->id,
        fn (): Conversation => Conversation::query()->where('external_thread_id', 'thread@example.com')->firstOrFail(),
    );
    expect($conversation->email_subject)->toBe('Re: Invoice #42 overdue');

    test()->withToken($token)->postJson("/api/conversations/{$conversation->id}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Sorted now.',
    ])->assertCreated();

    /** @var ArrayTransport $transport */
    $transport = Mail::getSymfonyTransport();
    $subjects = collect($transport->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage())
        ->filter(fn ($msg) => $msg instanceof Email)
        ->map(fn (Email $msg) => $msg->getSubject());

    expect($subjects)->toContain('Re: Invoice #42 overdue');
});

it('delivers an agent reply via the mailer', function (): void {
    // The array transport captures raw sends deterministically (Mail::fake only
    // records Mailables, not Mail::raw).
    config()->set('mail.default', 'array');

    [$org, $channel] = emailChannel();
    emailPost($org, $channel, ['from' => 'jamie@example.com', 'subject' => 'Hi', 'text' => 'hello'])->assertOk();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@email.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();
    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@email.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    $conversation = app(Tenancy::class)->run(
        $org->id,
        fn (): Conversation => Conversation::query()->where('channel_id', $channel->id)->firstOrFail(),
    );

    test()->withToken($token)->postJson("/api/conversations/{$conversation->id}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Your refund is processed.',
    ])->assertCreated();

    /** @var ArrayTransport $transport */
    $transport = Mail::getSymfonyTransport();
    $recipients = collect($transport->messages())
        ->flatMap(fn ($sent) => array_map(fn ($a) => $a->getAddress(), $sent->getEnvelope()->getRecipients()));

    expect($recipients)->toContain('jamie@example.com');
});
