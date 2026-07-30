<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Phase 35: developer platform — API keys + signed customer webhooks.
 */

/** @return array{0: Organization, 1: string} */
function p35Org(string $email = 'dev@p35.test'): array
{
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org, $email): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => $email,
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => $email,
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

// ── API keys ────────────────────────────────────────────────────────────────

it('creates a key shown once, authenticates the machine API, and revocation kills it', function (): void {
    [$org, $agentToken] = p35Org();

    $created = test()->withToken($agentToken)->postJson('/api/api-keys', ['name' => 'Zapier'])
        ->assertCreated();
    $plaintext = $created->json('key');
    expect($plaintext)->toStartWith('mk_live_');

    // The list never exposes the key again — only the prefix.
    $list = test()->withToken($agentToken)->getJson('/api/api-keys')->assertOk()->json('data');
    expect($list)->toHaveCount(1)
        ->and($list[0])->not->toHaveKey('key')
        ->and($list[0]['prefix'])->toStartWith('mk_live_');

    // Seed a conversation, then read it through the machine API.
    auth()->forgetGuards();
    $widgetToken = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated()->json('token');
    $conversationId = test()->withToken($widgetToken)
        ->postJson('/api/widget/conversations', [])->assertCreated()->json('conversation_id');

    auth()->forgetGuards();
    test()->withToken($plaintext)->getJson('/api/v1/conversations')
        ->assertOk()->assertJsonPath('data.0.conversation_id', $conversationId);
    test()->withToken($plaintext)->getJson("/api/v1/conversations/{$conversationId}/messages")
        ->assertOk();

    // Revoke → immediate 401.
    $keyId = $list[0]['api_key_id'];
    test()->withToken($agentToken)->deleteJson("/api/api-keys/{$keyId}")->assertNoContent();
    test()->withToken($plaintext)->getJson('/api/v1/conversations')->assertStatus(401);

    // Garbage keys 401 with the same shape (no oracle).
    test()->withToken('mk_live_'.Str::random(40))->getJson('/api/v1/conversations')->assertStatus(401);
});

it('scopes machine keys to their own organization', function (): void {
    [$orgA, $agentA] = p35Org('a@p35.test');
    $keyA = test()->withToken($agentA)->postJson('/api/api-keys', ['name' => 'A'])
        ->assertCreated()->json('key');

    // Org B has a conversation; org A's key must never see it.
    [$orgB] = p35Org('b@p35.test');
    auth()->forgetGuards();
    $widgetToken = test()->postJson('/api/widget/session', [
        'site_key' => $orgB->fresh()?->widget_site_key,
    ])->assertCreated()->json('token');
    $conversationB = test()->withToken($widgetToken)
        ->postJson('/api/widget/conversations', [])->assertCreated()->json('conversation_id');

    auth()->forgetGuards();
    expect(test()->withToken($keyA)->getJson('/api/v1/conversations')->assertOk()->json('data'))
        ->toBe([]);
    test()->withToken($keyA)->getJson("/api/v1/conversations/{$conversationB}")->assertNotFound();
});

// ── Webhooks ────────────────────────────────────────────────────────────────

it('delivers signed message.created webhooks to subscribed endpoints only', function (): void {
    Http::fake(['receiver.example/*' => Http::response('ok', 200)]);
    [$org, $agentToken] = p35Org();

    $created = test()->withToken($agentToken)->postJson('/api/webhook-endpoints', [
        'url' => 'https://receiver.example/hooks',
        'events' => ['message.created'],
    ])->assertCreated();
    $secret = $created->json('secret');
    expect($secret)->toStartWith('whsec_');

    // An endpoint subscribed ONLY to closes must stay silent for messages.
    test()->withToken($agentToken)->postJson('/api/webhook-endpoints', [
        'url' => 'https://receiver.example/closes-only',
        'events' => ['conversation.closed'],
    ])->assertCreated();

    // Visitor message → message.created delivery (sync queue runs inline).
    auth()->forgetGuards();
    $widgetToken = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated()->json('token');
    $conversationId = test()->withToken($widgetToken)
        ->postJson('/api/widget/conversations', [])->assertCreated()->json('conversation_id');
    test()->withToken($widgetToken)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Webhook me!',
    ])->assertCreated();

    Http::assertSent(function ($request) use ($secret): bool {
        if ($request->url() !== 'https://receiver.example/hooks') {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $request->body(), (string) $secret);

        return $request->header('X-MkEngage-Event')[0] === 'message.created'
            && hash_equals($expected, $request->header('X-MkEngage-Signature')[0] ?? '')
            && $request['data']['preview'] === 'Webhook me!';
    });

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://receiver.example/closes-only');
});

it('fires conversation.closed webhooks and the test event', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    [$org, $agentToken] = p35Org();

    $endpointId = test()->withToken($agentToken)->postJson('/api/webhook-endpoints', [
        'url' => 'https://receiver.example/all',
        'events' => ['message.created', 'conversation.closed'],
    ])->assertCreated()->json('webhook_endpoint_id');

    auth()->forgetGuards();
    $widgetToken = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated()->json('token');
    $conversationId = test()->withToken($widgetToken)
        ->postJson('/api/widget/conversations', [])->assertCreated()->json('conversation_id');

    auth()->forgetGuards();
    test()->withToken($agentToken)->patchJson("/api/conversations/{$conversationId}", ['status' => 'closed'])
        ->assertOk();
    Http::assertSent(fn ($request): bool => $request->header('X-MkEngage-Event')[0] === 'conversation.closed'
        && $request['data']['conversation_id'] === $conversationId);

    test()->withToken($agentToken)->postJson("/api/webhook-endpoints/{$endpointId}/test")
        ->assertOk();
    Http::assertSent(fn ($request): bool => $request->header('X-MkEngage-Event')[0] === 'webhook.test');
});

it('fires conversation.created, conversation.assigned and csat.received webhooks', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    [$org, $agentToken] = p35Org();

    test()->withToken($agentToken)->postJson('/api/webhook-endpoints', [
        'url' => 'https://receiver.example/catalog',
        'events' => ['conversation.created', 'conversation.assigned', 'csat.received'],
    ])->assertCreated();

    // A default department with the agent as member, so self-assign works
    // (widget conversations adopt the default department at creation).
    app(Tenancy::class)->run($org->id, function () use ($org): void {
        $dept = Department::query()->create([
            'name' => 'Support', 'is_default' => true, 'assignment_strategy' => 'manual',
        ]);
        $agent = User::query()->where('email', 'dev@p35.test')->firstOrFail();
        $dept->users()->attach($agent->id, [
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
        ]);
    });

    // First visitor message → conversation.created.
    auth()->forgetGuards();
    $widgetToken = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated()->json('token');
    $conversationId = test()->withToken($widgetToken)
        ->postJson('/api/widget/conversations', [])->assertCreated()->json('conversation_id');
    test()->withToken($widgetToken)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Hello',
    ])->assertCreated();

    Http::assertSent(fn ($request): bool => $request->header('X-MkEngage-Event')[0] === 'conversation.created'
        && $request['data']['conversation_id'] === $conversationId
        && $request['data']['first_sender_type'] === 'visitor');

    // Self-assign → conversation.assigned.
    auth()->forgetGuards();
    test()->withToken($agentToken)->postJson("/api/conversations/{$conversationId}/assign", ['assignee' => 'me'])
        ->assertOk();
    Http::assertSent(fn ($request): bool => $request->header('X-MkEngage-Event')[0] === 'conversation.assigned'
        && $request['data']['conversation_id'] === $conversationId
        && $request['data']['action'] === 'assignment.assigned');

    // Close, then the visitor rates → csat.received.
    test()->withToken($agentToken)->patchJson("/api/conversations/{$conversationId}", ['status' => 'closed'])
        ->assertOk();
    auth()->forgetGuards();
    test()->withToken($widgetToken)->postJson("/api/widget/conversations/{$conversationId}/rating", [
        'rating' => 5, 'comment' => 'Great help!',
    ])->assertCreated();

    Http::assertSent(fn ($request): bool => $request->header('X-MkEngage-Event')[0] === 'csat.received'
        && $request['data']['conversation_id'] === $conversationId
        && $request['data']['rating'] === 5
        && $request['data']['comment'] === 'Great help!');
});

it('rejects invalid webhook subscriptions', function (): void {
    [, $agentToken] = p35Org();

    test()->withToken($agentToken)->postJson('/api/webhook-endpoints', [
        'url' => 'not-a-url', 'events' => ['message.created'],
    ])->assertUnprocessable();

    test()->withToken($agentToken)->postJson('/api/webhook-endpoints', [
        'url' => 'https://x.example', 'events' => ['everything'],
    ])->assertUnprocessable();
});
