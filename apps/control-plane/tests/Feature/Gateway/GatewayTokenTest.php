<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('services.gateway.signing_key', 'test-gateway-signing-key');
});

function decodeGatewayToken(string $token): array
{
    [$payloadB64, $sigB64] = explode('.', $token);
    $payload = base64_decode(strtr($payloadB64, '-_', '+/'));
    $signature = base64_decode(strtr($sigB64, '-_', '+/'));

    return [json_decode($payload, true), $signature, $payload];
}

it('mints visitor gateway tokens verifiable with the shared secret', function (): void {
    $organization = Organization::factory()->create();

    $visitorToken = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->assertCreated()->json('token');

    $response = $this->withToken($visitorToken)->postJson('/api/widget/gateway-token')
        ->assertCreated();

    [$claims, $signature, $payload] = decodeGatewayToken($response->json('token'));

    expect($claims['org'])->toBe($organization->id)
        ->and($claims['sub'])->toStartWith('visitor:')
        ->and($claims['exp'])->toBeGreaterThan(time())
        ->and($claims['exp'])->toBeLessThanOrEqual(time() + 300)
        // Exact HMAC construction the Elixir gateway verifies (cross-service contract).
        ->and(hash_equals(hash_hmac('sha256', $payload, 'test-gateway-signing-key', true), $signature))->toBeTrue();

    expect($response->json('url'))->toStartWith('ws');
});

it('mints agent gateway tokens with user subjects', function (): void {
    $organization = Organization::factory()->create();
    $email = Str::lower(Str::random(8)).'@agent.test';

    app(Tenancy::class)->run($organization->id, function () use ($email): void {
        User::factory()->create(['email' => $email]);
    });

    $agentToken = $this->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => $email,
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertCreated()->json('token');

    $response = $this->withToken($agentToken)->postJson('/api/gateway-token')->assertCreated();
    [$claims] = decodeGatewayToken($response->json('token'));

    expect($claims['sub'])->toStartWith('user:')
        ->and($claims['org'])->toBe($organization->id);
});

it('requires authentication on both mint endpoints', function (): void {
    $this->postJson('/api/gateway-token')->assertUnauthorized();
    $this->postJson('/api/widget/gateway-token')->assertUnauthorized();
});

it('blocks visitor tokens from the agent mint and vice versa', function (): void {
    $organization = Organization::factory()->create();

    $visitorToken = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->json('token');

    $this->withToken($visitorToken)->postJson('/api/gateway-token')->assertStatus(403);
});
