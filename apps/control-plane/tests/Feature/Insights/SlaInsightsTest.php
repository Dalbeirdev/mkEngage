<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;

/**
 * Insights SLA aggregate: historical first-response outcomes (met / pending /
 * breached) per the org's per-priority targets.
 */

/** @return array{0: Organization, 1: string} */
function slaInsOrg(array $slaSettings): array
{
    $org = Organization::factory()->create();
    $org->settings = ['sla' => $slaSettings];
    $org->save();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@sla-ins.test',
            'name' => 'Ins Agent', 'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@sla-ins.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

function slaInsConversation(string $priority, string $createdAt, ?string $firstReplyAt): void
{
    // priority / first_agent_reply_at are guarded — set explicitly.
    $conversation = Conversation::query()->create([]);
    $conversation->priority = $priority;
    $conversation->created_at = $createdAt;
    $conversation->first_agent_reply_at = $firstReplyAt;
    $conversation->save();
}

it('counts met, pending and breached conversations against per-priority targets', function (): void {
    [$org, $token] = slaInsOrg([
        'enabled' => true,
        'targets' => ['urgent' => 5, 'high' => null, 'normal' => 30, 'low' => null],
    ]);

    app(Tenancy::class)->run($org->id, function (): void {
        // Urgent, replied after 3 min → met.
        slaInsConversation('urgent', now()->subMinutes(60)->toDateTimeString(), now()->subMinutes(57)->toDateTimeString());
        // Urgent, replied after 20 min (target 5) → breached.
        slaInsConversation('urgent', now()->subMinutes(60)->toDateTimeString(), now()->subMinutes(40)->toDateTimeString());
        // Normal, never replied, 60 min old (target 30) → breached.
        slaInsConversation('normal', now()->subMinutes(60)->toDateTimeString(), null);
        // Normal, never replied, 10 min old (target 30) → pending.
        slaInsConversation('normal', now()->subMinutes(10)->toDateTimeString(), null);
        // Low has no target → not tracked.
        slaInsConversation('low', now()->subMinutes(60)->toDateTimeString(), null);
    });

    $sla = test()->withToken($token)->getJson('/api/insights/overview')->assertOk()->json('sla');

    expect($sla)->toBe([
        'enabled' => true,
        'tracked' => 4,
        'met' => 1,
        'pending' => 1,
        'breached' => 2,
        'breach_rate' => 0.5,
    ]);
});

it('reports a disabled zeroed aggregate when the org has no SLA configured', function (): void {
    [$org, $token] = slaInsOrg(['enabled' => false]);

    app(Tenancy::class)->run($org->id, function (): void {
        slaInsConversation('urgent', now()->subMinutes(60)->toDateTimeString(), null);
    });

    $sla = test()->withToken($token)->getJson('/api/insights/overview')->assertOk()->json('sla');

    expect($sla)->toBe([
        'enabled' => false,
        'tracked' => 0,
        'met' => 0,
        'pending' => 0,
        'breached' => 0,
        'breach_rate' => 0,
    ]);
});
