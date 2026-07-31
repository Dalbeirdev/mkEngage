<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * notifications:missed — emails agents when a customer's latest message has
 * waited past the org threshold; one email per waiting message.
 */
function missedOrg(bool $enabled, int $minutes = 5): Organization
{
    $org = Organization::factory()->create();
    $org->settings = ['notifications' => [
        'missed_email_enabled' => $enabled, 'missed_after_minutes' => $minutes,
    ]];
    $org->save();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@missed.test',
            'name' => 'Missed Agent', 'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    return $org;
}

function missedConversation(Organization $org, string $senderType, string $sentAt): Conversation
{
    return app(Tenancy::class)->run($org->id, function () use ($senderType, $sentAt): Conversation {
        $conversation = Conversation::query()->create([]);
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => $senderType,
            'sender_id' => Str::uuid7()->toString(),
            'sequence_number' => 1,
            'content_type' => 'text',
            'body' => 'Is anyone there? I need help with my order.',
            'lifecycle_state' => 'persisted',
            'idempotency_key' => Str::uuid7()->toString(),
            'sent_at' => $sentAt,
        ]);
        $conversation->last_sequence = 1;
        $conversation->save();

        return $conversation;
    });
}

function missedMailCount(): int
{
    /** @var ArrayTransport $transport */
    $transport = Mail::getSymfonyTransport();

    return count($transport->messages());
}

it('emails agents once per waiting message past the threshold', function (): void {
    config()->set('mail.default', 'array');
    $org = missedOrg(enabled: true, minutes: 5);
    $conversation = missedConversation($org, 'visitor', now()->subMinutes(10)->toDateTimeString());

    Artisan::call('notifications:missed');
    expect(missedMailCount())->toBe(1);

    /** @var ArrayTransport $transport */
    $transport = Mail::getSymfonyTransport();
    $sent = $transport->messages()->first();
    $recipients = array_map(fn ($a) => $a->getAddress(), $sent->getEnvelope()->getRecipients());
    expect($recipients)->toContain('agent@missed.test');

    // Second run: already notified, no duplicate email.
    Artisan::call('notifications:missed');
    expect(missedMailCount())->toBe(1);

    // Marker advanced to the notified sequence.
    $marker = app(Tenancy::class)->run($org->id, fn (): int => (int) Conversation::query()
        ->whereKey($conversation->id)->value('missed_notified_sequence'));
    expect($marker)->toBe(1);
});

it('stays quiet inside the response window and when disabled', function (): void {
    config()->set('mail.default', 'array');

    $fresh = missedOrg(enabled: true, minutes: 5);
    missedConversation($fresh, 'visitor', now()->subMinutes(2)->toDateTimeString());

    $disabled = missedOrg(enabled: false);
    missedConversation($disabled, 'visitor', now()->subMinutes(30)->toDateTimeString());

    Artisan::call('notifications:missed');
    expect(missedMailCount())->toBe(0);
});

it('treats an agent reply as answered', function (): void {
    config()->set('mail.default', 'array');
    $org = missedOrg(enabled: true, minutes: 5);
    $conversation = missedConversation($org, 'visitor', now()->subMinutes(30)->toDateTimeString());

    app(Tenancy::class)->run($org->id, function () use ($conversation): void {
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'agent',
            'sender_id' => Str::uuid7()->toString(),
            'sequence_number' => 2,
            'content_type' => 'text',
            'body' => 'On it!',
            'lifecycle_state' => 'persisted',
            'idempotency_key' => Str::uuid7()->toString(),
            'sent_at' => now()->subMinutes(20)->toDateTimeString(),
        ]);
        $conversation->last_sequence = 2;
        $conversation->save();
    });

    Artisan::call('notifications:missed');
    expect(missedMailCount())->toBe(0);
});

it('round-trips the notification settings endpoint', function (): void {
    $org = missedOrg(enabled: false);

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@missed.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    test()->withToken($token)->getJson('/api/organization/notifications')
        ->assertOk()->assertJson(['missed_email_enabled' => false, 'missed_after_minutes' => 5]);

    test()->withToken($token)->putJson('/api/organization/notifications', [
        'missed_email_enabled' => true, 'missed_after_minutes' => 15,
    ])->assertOk()->assertJson(['missed_email_enabled' => true, 'missed_after_minutes' => 15]);

    test()->withToken($token)->putJson('/api/organization/notifications', [
        'missed_email_enabled' => true, 'missed_after_minutes' => 500,
    ])->assertStatus(422);
});
