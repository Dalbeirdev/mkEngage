<?php

declare(strict_types=1);

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Phase 34: Insights v2 — FRT, channel split, hourly histogram, leaderboard.
 */

/** @return array{0: Organization, 1: string} */
function p34Org(): array
{
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@p34.test',
            'name' => 'Asha Agent', 'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@p34.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

function p34Message(Conversation $conversation, string $senderType, string $senderId, int $sequence, string $sentAt): void
{
    Message::query()->create([
        'conversation_id' => $conversation->id,
        'sender_type' => $senderType,
        'sender_id' => $senderId,
        'sequence_number' => $sequence,
        'content_type' => 'text',
        'body' => "seq {$sequence}",
        'lifecycle_state' => 'persisted',
        'idempotency_key' => Str::uuid7()->toString(),
        'sent_at' => $sentAt,
    ]);
    $conversation->update(['last_sequence' => $sequence]);
}

it('computes first-response times (agent vs bot), channel split, hourly and leaderboard', function (): void {
    [$org, $token] = p34Org();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        $agent = User::query()->where('email', 'agent@p34.test')->firstOrFail();
        $visitorId = Str::uuid7()->toString();

        // Web conversation: inbound 10:00:00 → bot 10:00:05 → agent 10:02:00.
        $web = Conversation::query()->create(['status' => 'closed', 'assigned_agent_id' => $agent->id]);
        // closed_at / csat_rating are guarded — set explicitly.
        $web->closed_at = now();
        $web->csat_rating = 4;
        $web->save();
        p34Message($web, 'visitor', $visitorId, 1, now()->setTime(10, 0, 0)->toDateTimeString());
        p34Message($web, 'chatbot', Str::uuid7()->toString(), 2, now()->setTime(10, 0, 5)->toDateTimeString());
        p34Message($web, 'agent', $agent->id, 3, now()->setTime(10, 2, 0)->toDateTimeString());

        // Telegram conversation: inbound 14:00 → agent 14:01 (60s).
        $channel = Channel::query()->create([
            'organization_id' => $org->id, 'type' => 'telegram', 'name' => 'TG',
            'status' => 'active', 'config' => ['bot_token' => 'x'], 'webhook_verify_token' => 'v',
        ]);
        $tg = Conversation::query()->create(['channel_id' => $channel->id, 'external_thread_id' => '5']);
        p34Message($tg, 'contact', Str::uuid7()->toString(), 1, now()->setTime(14, 0, 0)->toDateTimeString());
        p34Message($tg, 'agent', $agent->id, 2, now()->setTime(14, 1, 0)->toDateTimeString());
    });

    $overview = test()->withToken($token)
        ->getJson('/api/insights/overview')
        ->assertOk()->json();

    // Channel split: 1 web + 1 telegram.
    expect($overview['by_channel'])->toBe(['web' => 1, 'whatsapp' => 0, 'telegram' => 1, 'messenger' => 0, 'instagram' => 0, 'email' => 0]);

    // FRT: agent times 120s and 60s → avg 90, median 90; bot 5s.
    expect($overview['first_response']['agent_avg_seconds'])->toBe(90)
        ->and($overview['first_response']['agent_median_seconds'])->toBe(90)
        ->and($overview['first_response']['bot_avg_seconds'])->toBe(5)
        ->and($overview['first_response']['answered_by_agent'])->toBe(2);

    // Hourly histogram: 24 buckets; hour 10 has 3 messages, hour 14 has 2.
    expect($overview['hourly'])->toHaveCount(24);
    $byHour = collect($overview['hourly'])->keyBy('hour');
    expect($byHour[10]['messages'])->toBe(3)
        ->and($byHour[14]['messages'])->toBe(2);

    // Leaderboard: Asha with 2 replies, 1 closed, avg CSAT 4.
    expect($overview['agents'])->toHaveCount(1)
        ->and($overview['agents'][0]['name'])->toBe('Asha Agent')
        ->and($overview['agents'][0]['replies'])->toBe(2)
        ->and($overview['agents'][0]['closed'])->toBe(1)
        ->and($overview['agents'][0]['avg_csat'])->toEqual(4);
});

it('returns null first-response metrics when nothing was answered', function (): void {
    [, $token] = p34Org();

    $overview = test()->withToken($token)->getJson('/api/insights/overview')->assertOk()->json();

    expect($overview['first_response']['agent_avg_seconds'])->toBeNull()
        ->and($overview['first_response']['answered_by_agent'])->toBe(0)
        ->and($overview['agents'])->toBe([]);
});
