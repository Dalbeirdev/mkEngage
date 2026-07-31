<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/**
 * Stripe checkout + webhook plan lifecycle. Stripe's API is faked; webhook
 * requests carry real t/v1 HMAC signatures computed with the test secret.
 */
function stripeOrg(): array
{
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@stripe.test',
            'name' => 'Stripe Agent', 'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@stripe.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

function stripeConfig(): void
{
    config()->set('services.stripe.secret', 'sk_test_secret');
    config()->set('services.stripe.webhook_secret', 'whsec_test');
    config()->set('services.stripe.prices.pro', 'price_pro_123');
    config()->set('services.stripe.prices.business', 'price_biz_456');
}

function stripeSigned(array $event): array
{
    $payload = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

    return [$payload, "t={$timestamp},v1={$signature}"];
}

it('reports checkout availability only when Stripe is configured', function (): void {
    [, $token] = stripeOrg();

    expect(test()->withToken($token)->getJson('/api/organization/billing')->json('checkout_enabled'))->toBeFalse();

    stripeConfig();
    $billing = test()->withToken($token)->getJson('/api/organization/billing')->json();
    expect($billing['checkout_enabled'])->toBeTrue()
        ->and($billing['checkout_plans'])->toBe(['pro', 'business']);
});

it('creates a Stripe checkout session and returns its URL', function (): void {
    stripeConfig();
    [$org, $token] = stripeOrg();

    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
        ]),
    ]);

    test()->withToken($token)
        ->postJson('/api/organization/billing/checkout', ['plan' => 'pro'])
        ->assertCreated()
        ->assertJson(['url' => 'https://checkout.stripe.com/c/pay/cs_test_1']);

    Http::assertSent(function ($request) use ($org): bool {
        return str_contains($request->url(), 'checkout/sessions')
            && $request['client_reference_id'] === $org->id
            && $request['line_items[0][price]'] === 'price_pro_123'
            && $request['metadata[plan]'] === 'pro';
    });
});

it('answers 409 for checkout when Stripe is not configured', function (): void {
    [, $token] = stripeOrg();

    test()->withToken($token)
        ->postJson('/api/organization/billing/checkout', ['plan' => 'pro'])
        ->assertStatus(409);
});

it('rejects webhooks with a bad signature', function (): void {
    stripeConfig();

    test()->call('POST', '/api/billing/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=deadbeef',
        'CONTENT_TYPE' => 'application/json',
    ], '{"type":"invoice.paid"}')->assertStatus(400);
});

it('activates the plan on checkout.session.completed and cancels on subscription.deleted', function (): void {
    stripeConfig();
    [$org] = stripeOrg();

    [$payload, $signature] = stripeSigned([
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'client_reference_id' => $org->id,
            'customer' => 'cus_test_1',
            'subscription' => 'sub_test_1',
            'metadata' => ['plan' => 'pro'],
        ]],
    ]);
    test()->call('POST', '/api/billing/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    $org->refresh();
    expect($org->plan)->toBe('pro')
        ->and($org->white_label)->toBeTruthy()
        ->and($org->stripe_customer_id)->toBe('cus_test_1')
        ->and($org->stripe_subscription_id)->toBe('sub_test_1')
        ->and($org->plan_expires_at)->not->toBeNull();

    [$payload, $signature] = stripeSigned([
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => ['id' => 'sub_test_1']],
    ]);
    test()->call('POST', '/api/billing/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    $org->refresh();
    expect($org->plan)->toBe('free')
        ->and($org->white_label)->toBeFalsy()
        ->and($org->stripe_subscription_id)->toBeNull();
});

it('extends the expiry to the paid period end on invoice.paid', function (): void {
    stripeConfig();
    [$org] = stripeOrg();

    $org->stripe_customer_id = 'cus_test_2';
    $org->stripe_subscription_id = 'sub_test_2';
    $org->plan = 'pro';
    $org->save();

    $periodEnd = now()->addYear()->timestamp;
    [$payload, $signature] = stripeSigned([
        'type' => 'invoice.paid',
        'data' => ['object' => [
            'customer' => 'cus_test_2',
            'subscription' => 'sub_test_2',
            'subscription_details' => ['metadata' => ['plan' => 'pro']],
            'lines' => ['data' => [['period' => ['end' => $periodEnd]]]],
        ]],
    ]);
    test()->call('POST', '/api/billing/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    $org->refresh();
    expect($org->plan)->toBe('pro')
        ->and($org->plan_expires_at?->timestamp)->toBe($periodEnd + 3 * 86400);
});
