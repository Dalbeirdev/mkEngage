<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('writes a contract-shaped outbox event in the same transaction as the message', function (): void {
    Http::fake();
    $organization = Organization::factory()->create();

    $visitorToken = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->assertCreated()->json('token');

    $conversationId = $this->withToken($visitorToken)
        ->postJson('/api/widget/conversations', [])->json('conversation_id');

    $sent = $this->withToken($visitorToken)
        ->postJson("/api/widget/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'Outbox me. '.str_repeat('x', 200),
        ])->assertCreated();

    $event = DB::table('outbox_events')->where('event_type', 'conv.message.accepted.v1')->first();

    expect($event)->not->toBeNull()
        ->and($event->organization_id)->toBe($organization->id)
        ->and($event->published_at)->toBeNull();

    $envelope = json_decode((string) $event->envelope, true);

    // Envelope contract (contracts/events/envelope.schema.json).
    expect($envelope['specversion'])->toBe('1.0')
        ->and($envelope['type'])->toBe('conv.message.accepted.v1')
        ->and($envelope['source'])->toBe('control-plane')
        ->and($envelope['orgid'])->toBe($organization->id)
        ->and($envelope['id'])->toBe($event->id);

    // Payload contract: data-minimized — 140-char preview, never the full body.
    expect($envelope['data']['message_id'])->toBe($sent->json('message_id'))
        ->and($envelope['data']['sequence_number'])->toBe(1)
        ->and(mb_strlen($envelope['data']['content_preview']))->toBeLessThanOrEqual(140)
        ->and($envelope['data'])->not->toHaveKey('body');
});

it('does not write outbox events for duplicate sends', function (): void {
    Http::fake();
    $organization = Organization::factory()->create();

    $visitorToken = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->json('token');
    $conversationId = $this->withToken($visitorToken)
        ->postJson('/api/widget/conversations', [])->json('conversation_id');

    $key = (string) Str::uuid7();
    foreach ([201, 200] as $expected) {
        $this->withToken($visitorToken)
            ->postJson("/api/widget/conversations/{$conversationId}/messages", [
                'idempotency_key' => $key,
                'content_type' => 'text',
                'body' => 'Once',
            ])->assertStatus($expected);
    }

    expect(DB::table('outbox_events')->where('event_type', 'conv.message.accepted.v1')->count())
        ->toBe(1);
});

it('relays outbox events to JetStream and marks them published', function (): void {
    if ((string) env('NATS_URL') === '') {
        $this->markTestSkipped('NATS relay test requires NATS_URL (REQUIRED in CI).');
    }
    config()->set('services.nats.url', env('NATS_URL'));

    Http::fake();
    $organization = Organization::factory()->create();

    $visitorToken = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->json('token');
    $conversationId = $this->withToken($visitorToken)
        ->postJson('/api/widget/conversations', [])->json('conversation_id');

    $this->withToken($visitorToken)
        ->postJson("/api/widget/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'Relay me',
        ])->assertCreated();

    expect(DB::table('outbox_events')->whereNull('published_at')->count())->toBeGreaterThan(0);

    Artisan::call('outbox:relay', ['--once' => true]);

    expect(DB::table('outbox_events')->whereNull('published_at')->count())->toBe(0)
        ->and(DB::table('outbox_events')->whereNotNull('published_at')->count())->toBeGreaterThan(0);
});
