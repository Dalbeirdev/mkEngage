<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('services.gateway.internal_url', 'http://gateway.test');
    config()->set('services.gateway.internal_token', 'test-broadcast-token');
});

function broadcastFixture(): array
{
    $organization = Organization::factory()->create();

    $visitorToken = test()->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->assertCreated()->json('token');

    $conversationId = test()->withToken($visitorToken)
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    return [$organization, $visitorToken, $conversationId];
}

it('fans persisted REST messages out to the gateway after commit', function (): void {
    Http::fake(['gateway.test/*' => Http::response(['status' => 'broadcast'])]);
    [, $visitorToken, $conversationId] = broadcastFixture();

    $message = $this->withToken($visitorToken)
        ->postJson("/api/widget/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'Fan me out',
        ])->assertCreated();

    Http::assertSent(function ($request) use ($conversationId, $message): bool {
        return str_contains($request->url(), '/internal/broadcast')
            && $request['conversation_id'] === $conversationId
            && $request['message_id'] === $message->json('message_id')
            && $request['sequence_number'] === 1
            && $request->hasHeader('Authorization', 'Bearer test-broadcast-token');
    });
});

it('does not fan out duplicate sends', function (): void {
    Http::fake(['gateway.test/*' => Http::response(['status' => 'broadcast'])]);
    [, $visitorToken, $conversationId] = broadcastFixture();
    $key = (string) Str::uuid7();

    foreach ([201, 200] as $expected) {
        $this->withToken($visitorToken)
            ->postJson("/api/widget/conversations/{$conversationId}/messages", [
                'idempotency_key' => $key,
                'content_type' => 'text',
                'body' => 'Once',
            ])->assertStatus($expected);
    }

    Http::assertSentCount(1);
});

it('never fails the sender when the gateway is down', function (): void {
    Http::fake(['gateway.test/*' => fn () => throw new ConnectionException('down')]);
    [, $visitorToken, $conversationId] = broadcastFixture();

    $this->withToken($visitorToken)
        ->postJson("/api/widget/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'Still persists',
        ])->assertCreated();
});

it('skips fan-out silently when the gateway is not configured', function (): void {
    config()->set('services.gateway.internal_url', null);
    Http::fake();
    [, $visitorToken, $conversationId] = broadcastFixture();

    $this->withToken($visitorToken)
        ->postJson("/api/widget/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'No gateway',
        ])->assertCreated();

    Http::assertNothingSent();
});
