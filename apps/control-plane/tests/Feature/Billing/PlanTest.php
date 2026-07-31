<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/**
 * Billing v1: plan limits enforced on resource creation, operator
 * activation via org:plan, and daily expiry reconciliation.
 */
function planOrg(): array
{
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@plan.test',
            'name' => 'Plan Agent', 'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@plan.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

it('reports the free plan with usage and the catalog', function (): void {
    [, $token] = planOrg();

    $billing = test()->withToken($token)->getJson('/api/organization/billing')->assertOk()->json();

    expect($billing['plan'])->toBe('free')
        ->and($billing['white_label'])->toBeFalse()
        ->and($billing['limits'])->toBe(['channels' => 2, 'chatbots' => 3])
        ->and($billing['usage'])->toBe(['channels' => 0, 'chatbots' => 0])
        ->and(array_keys($billing['catalog']))->toBe(['free', 'pro', 'business']);
});

it('blocks channel creation past the free limit and unblocks after org:plan pro', function (): void {
    [$org, $token] = planOrg();

    $payload = fn (int $i): array => [
        'type' => 'telegram', 'name' => "TG {$i}", 'bot_token' => "tok-{$i}",
    ];

    test()->withToken($token)->postJson('/api/channels', $payload(1))->assertCreated();
    test()->withToken($token)->postJson('/api/channels', $payload(2))->assertCreated();

    test()->withToken($token)->postJson('/api/channels', $payload(3))
        ->assertStatus(422)
        ->assertJsonValidationErrors('plan');

    Artisan::call('org:plan', ['slug' => $org->slug, 'plan' => 'pro']);

    test()->withToken($token)->postJson('/api/channels', $payload(3))->assertCreated();

    // Plan activation also grants the white-label entitlement.
    expect(Organization::query()->whereKey($org->id)->value('white_label'))->toBeTruthy();
});

it('blocks chatbot creation past the free limit', function (): void {
    [, $token] = planOrg();

    foreach ([1, 2, 3] as $i) {
        test()->withToken($token)->postJson('/api/chatbots', ['name' => "Bot {$i}"])->assertCreated();
    }
    test()->withToken($token)->postJson('/api/chatbots', ['name' => 'Bot 4'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('plan');
});

it('treats an expired paid plan as free and reconciles it daily', function (): void {
    [$org, $token] = planOrg();

    Artisan::call('org:plan', ['slug' => $org->slug, 'plan' => 'pro', '--months' => 1]);

    // Effective immediately: billing reports pro.
    expect(test()->withToken($token)->getJson('/api/organization/billing')->json('plan'))->toBe('pro');

    $this->travel(2)->months();

    // Reads treat it as free even before the sweep runs.
    expect(test()->withToken($token)->getJson('/api/organization/billing')->json('plan'))->toBe('free');

    Artisan::call('plans:expire');

    $org->refresh();
    expect($org->plan)->toBe('free')
        ->and($org->plan_expires_at)->toBeNull()
        ->and($org->white_label)->toBeFalse();
});
