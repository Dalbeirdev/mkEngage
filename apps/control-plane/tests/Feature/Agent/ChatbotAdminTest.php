<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Str;

function adminToken(?Organization $organization = null): array
{
    $organization ??= Organization::factory()->create();
    $email = Str::lower(Str::random(8)).'@admin.test';

    app(Tenancy::class)->run($organization->id, function () use ($email): void {
        User::factory()->create(['email' => $email]);
    });

    $token = test()->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => $email,
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$organization, $token];
}

it('creates chatbots as drafts and lists them', function (): void {
    [, $token] = adminToken();

    $created = $this->withToken($token)->postJson('/api/chatbots', [
        'name' => 'Support Bot',
        'system_prompt' => 'Be kind.',
        'provider' => 'fake',
    ])->assertCreated();

    expect($created->json('status'))->toBe('draft');

    $list = $this->withToken($token)->getJson('/api/chatbots')->assertOk();
    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.name'))->toBe('Support Bot');
});

it('enforces the single-active invariant when activating', function (): void {
    [, $token] = adminToken();

    $a = $this->withToken($token)->postJson('/api/chatbots', ['name' => 'Bot A'])->json('chatbot_id');
    $b = $this->withToken($token)->postJson('/api/chatbots', ['name' => 'Bot B'])->json('chatbot_id');

    $this->withToken($token)->patchJson("/api/chatbots/{$a}", ['status' => 'active'])->assertOk();
    $this->withToken($token)->patchJson("/api/chatbots/{$b}", ['status' => 'active'])->assertOk();

    $list = $this->withToken($token)->getJson('/api/chatbots')->json('data');
    $byId = collect($list)->keyBy('chatbot_id');

    expect($byId[$a]['status'])->toBe('paused')
        ->and($byId[$b]['status'])->toBe('active');
});

it('updates prompt and provider routing', function (): void {
    [, $token] = adminToken();
    $id = $this->withToken($token)->postJson('/api/chatbots', ['name' => 'Bot'])->json('chatbot_id');

    $updated = $this->withToken($token)->patchJson("/api/chatbots/{$id}", [
        'system_prompt' => 'You are Acme support. Be terse.',
        'provider' => 'anthropic',
        'model' => 'claude-haiku-4-5-20251001',
    ])->assertOk();

    expect($updated->json('provider'))->toBe('anthropic')
        ->and($updated->json('model'))->toBe('claude-haiku-4-5-20251001');
});

it('rejects unknown providers and invalid status values', function (): void {
    [, $token] = adminToken();
    $id = $this->withToken($token)->postJson('/api/chatbots', ['name' => 'Bot'])->json('chatbot_id');

    $this->withToken($token)->patchJson("/api/chatbots/{$id}", ['provider' => 'skynet'])
        ->assertUnprocessable();
    $this->withToken($token)->patchJson("/api/chatbots/{$id}", ['status' => 'destroyed'])
        ->assertUnprocessable();
});

it('is tenant-scoped: another org sees nothing and gets 404 on direct access', function (): void {
    [, $tokenA] = adminToken();
    $id = $this->withToken($tokenA)->postJson('/api/chatbots', ['name' => 'Private Bot'])->json('chatbot_id');

    [, $tokenB] = adminToken();

    expect($this->withToken($tokenB)->getJson('/api/chatbots')->json('data'))->toHaveCount(0);
    $this->withToken($tokenB)->getJson("/api/chatbots/{$id}")->assertNotFound();
    $this->withToken($tokenB)->patchJson("/api/chatbots/{$id}", ['name' => 'pwned'])->assertNotFound();
});

it('blocks visitor tokens from chatbot admin', function (): void {
    [$organization] = adminToken();

    $visitorToken = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->assertCreated()->json('token');

    $this->withToken($visitorToken)->getJson('/api/chatbots')->assertStatus(403);
});
