<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Str;

function identifiedFixture(): array
{
    $organization = Organization::factory()->create();

    $token = test()->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->assertCreated()->json('token');

    return [$organization, $token];
}

function sign(Organization $organization, string $externalId): string
{
    return hash_hmac('sha256', $externalId, (string) $organization->widget_signing_secret);
}

it('links a visitor to a contact with a valid customer-backend signature', function (): void {
    [$organization, $token] = identifiedFixture();

    $response = $this->withToken($token)->postJson('/api/widget/identify', [
        'external_id' => 'cust-42',
        'signature' => sign($organization, 'cust-42'),
        'email' => 'jane@customer.test',
        'name' => 'Jane Doe',
    ])->assertOk();

    expect($response->json('display_name'))->toBe('Jane Doe');

    // The conversation surface now shows the contact.
    $conversationId = $this->withToken($token)
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    $agentToken = agentTokenFor($organization);
    $list = $this->withToken($agentToken)->getJson('/api/conversations')->assertOk();

    expect($list->json('data.0.conversation_id'))->toBe($conversationId)
        ->and($list->json('data.0.contact_name'))->toBe('Jane Doe')
        ->and($list->json('data.0.contact_email'))->toBe('jane@customer.test');
});

it('rejects forged signatures and stays anonymous', function (): void {
    [, $token] = identifiedFixture();

    $this->withToken($token)->postJson('/api/widget/identify', [
        'external_id' => 'cust-42',
        'signature' => str_repeat('ab', 32), // well-formed but wrong
    ])->assertStatus(422);
});

it('rejects a signature computed with another orgs secret (no cross-org identity)', function (): void {
    [, $token] = identifiedFixture();
    $other = Organization::factory()->create();

    $this->withToken($token)->postJson('/api/widget/identify', [
        'external_id' => 'cust-42',
        'signature' => sign($other, 'cust-42'),
    ])->assertStatus(422);
});

it('reuses the same contact for repeat identification (find-or-create by external id)', function (): void {
    [$organization, $tokenA] = identifiedFixture();

    $first = $this->withToken($tokenA)->postJson('/api/widget/identify', [
        'external_id' => 'cust-7',
        'signature' => sign($organization, 'cust-7'),
        'name' => 'Sam',
    ])->assertOk();

    // Same person, new device/browser ⇒ new visitor, same contact.
    $tokenB = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->assertCreated()->json('token');

    $second = $this->withToken($tokenB)->postJson('/api/widget/identify', [
        'external_id' => 'cust-7',
        'signature' => sign($organization, 'cust-7'),
    ])->assertOk();

    expect($second->json('contact_id'))->toBe($first->json('contact_id'));
});

it('back-fills contact onto the visitors earlier conversations', function (): void {
    [$organization, $token] = identifiedFixture();

    $conversationId = $this->withToken($token)
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    $this->withToken($token)->postJson('/api/widget/identify', [
        'external_id' => 'cust-9',
        'signature' => sign($organization, 'cust-9'),
        'name' => 'Backfilled Person',
    ])->assertOk();

    $agentToken = agentTokenFor($organization);
    $shown = $this->withToken($agentToken)
        ->getJson("/api/conversations/{$conversationId}")
        ->assertOk();

    expect($shown->json('contact_name'))->toBe('Backfilled Person');
});

it('lists contacts on the agent surface, tenant-scoped', function (): void {
    [$organization, $token] = identifiedFixture();

    $this->withToken($token)->postJson('/api/widget/identify', [
        'external_id' => 'cust-1',
        'signature' => sign($organization, 'cust-1'),
        'email' => 'one@customer.test',
        'name' => 'Contact One',
    ])->assertOk();

    $agentToken = agentTokenFor($organization);
    $list = $this->withToken($agentToken)->getJson('/api/contacts')->assertOk();

    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.name'))->toBe('Contact One');

    // Another org sees nothing.
    $other = Organization::factory()->create();
    $otherAgent = agentTokenFor($other);
    expect($this->withToken($otherAgent)->getJson('/api/contacts')->json('data'))->toHaveCount(0);
});

/** Create an agent user in the org and return a user token. */
function agentTokenFor(Organization $organization): string
{
    $email = Str::lower(Str::random(8)).'@agent.test';

    app(Tenancy::class)->run($organization->id, function () use ($email): void {
        User::factory()->create(['email' => $email]);
    });

    return test()->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => $email,
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertCreated()->json('token');
}
