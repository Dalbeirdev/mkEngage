<?php

declare(strict_types=1);

use App\Models\Chatbot;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Organization;
use App\Models\User;
use App\Services\KnowledgeChunker;
use App\Services\KnowledgeRetriever;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function knowledgeToken(): array
{
    $organization = Organization::factory()->create();
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

function fakeEmbedApi(): void
{
    Http::fake([
        '127.0.0.1:8100/v1/embed' => function ($request) {
            $texts = $request['texts'];

            return Http::response([
                'vectors' => array_map(fn () => array_fill(0, 4, 0.5), $texts),
                'provider' => 'fake',
                'dimensions' => 4,
            ]);
        },
    ]);
}

it('chunks paragraph-aware and deterministically', function (): void {
    $chunker = new KnowledgeChunker;

    $body = "Para one.\n\nPara two.\n\n".str_repeat('x', 1700);
    $chunks = $chunker->chunk($body);

    expect($chunks[0])->toBe("Para one.\n\nPara two.")
        ->and(count($chunks))->toBe(4) // packed + 800 + 800 + 100
        ->and($chunker->chunk($body))->toBe($chunks);

    expect($chunker->chunk("  \n\n  "))->toBe([]);
});

it('creates documents, ingests chunks via the queue, and lists them', function (): void {
    fakeEmbedApi();
    [, $token] = knowledgeToken();

    $created = $this->withToken($token)->postJson('/api/knowledge/documents', [
        'title' => 'Shipping policy',
        'body' => "Standard shipping to Canada takes 5-7 business days.\n\nExpress shipping takes 2 days.",
    ])->assertCreated();

    // Sync queue: ingestion ran after commit.
    $list = $this->withToken($token)->getJson('/api/knowledge/documents')->assertOk();

    expect($list->json('data.0.document_id'))->toBe($created->json('document_id'))
        ->and($list->json('data.0.status'))->toBe('ready')
        ->and($list->json('data.0.chunk_count'))->toBe(1);
});

it('survives embed-service failure by falling back to FTS-only chunks', function (): void {
    Http::fake(['127.0.0.1:8100/*' => Http::response(null, 503)]);
    [, $token] = knowledgeToken();

    $this->withToken($token)->postJson('/api/knowledge/documents', [
        'title' => 'Doc',
        'body' => 'Some content here.',
    ])->assertCreated();

    $list = $this->withToken($token)->getJson('/api/knowledge/documents')->assertOk();
    expect($list->json('data.0.status'))->toBe('ready');
});

it('deletes documents with their chunks', function (): void {
    fakeEmbedApi();
    [$organization, $token] = knowledgeToken();

    $id = $this->withToken($token)->postJson('/api/knowledge/documents', [
        'title' => 'Doomed', 'body' => 'Gone soon.',
    ])->json('document_id');

    $this->withToken($token)->deleteJson("/api/knowledge/documents/{$id}")->assertOk();

    app(Tenancy::class)->run($organization->id, function (): void {
        expect(KnowledgeChunk::query()->count())->toBe(0)
            ->and(KnowledgeDocument::query()->count())->toBe(0);
    });
});

it('is tenant-scoped and blocks visitor tokens', function (): void {
    fakeEmbedApi();
    [$organization, $token] = knowledgeToken();
    $id = $this->withToken($token)->postJson('/api/knowledge/documents', [
        'title' => 'Private', 'body' => 'Secret sauce recipe.',
    ])->json('document_id');

    [, $otherToken] = knowledgeToken();
    $this->app['auth']->forgetGuards();
    expect($this->withToken($otherToken)->getJson('/api/knowledge/documents')->json('data'))
        ->toHaveCount(0);
    $this->withToken($otherToken)->deleteJson("/api/knowledge/documents/{$id}")->assertNotFound();

    $visitorToken = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->json('token');
    $this->app['auth']->forgetGuards();
    $this->withToken($visitorToken)->getJson('/api/knowledge/documents')->assertStatus(403);
});

it('retrieves relevant chunks via FTS and grounds chatbot replies', function (): void {
    if (! runningOnPostgres()) {
        $this->markTestSkipped('Hybrid retrieval requires PostgreSQL (REQUIRED in CI).');
    }

    fakeEmbedApi();
    [$organization, $token] = knowledgeToken();

    app(Tenancy::class)->run($organization->id, function (): void {
        Chatbot::query()->create([
            'name' => 'Bot', 'status' => 'active', 'provider' => 'fake',
        ]);
    });

    $this->withToken($token)->postJson('/api/knowledge/documents', [
        'title' => 'Shipping policy',
        'body' => 'Standard shipping to Canada takes 5-7 business days.',
    ])->assertCreated();
    $this->withToken($token)->postJson('/api/knowledge/documents', [
        'title' => 'Returns policy',
        'body' => 'Returns are accepted within 30 days of purchase.',
    ])->assertCreated();

    // Direct retrieval: the shipping chunk must outrank the returns chunk.
    $chunks = app(Tenancy::class)->run($organization->id, function (): array {
        return app(KnowledgeRetriever::class)->retrieve('how long does shipping to canada take');
    });

    expect($chunks)->not->toBeEmpty()
        ->and($chunks[0]['document_title'])->toBe('Shipping policy')
        ->and($chunks[0]['content'])->toContain('5-7 business days');

    // End-to-end: visitor asks, the bot's reply is grounded in the doc.
    Http::fake([
        '127.0.0.1:8100/v1/embed' => Http::response(['vectors' => [[0.5, 0.5]], 'provider' => 'fake', 'dimensions' => 2]),
        '127.0.0.1:8100/v1/reply' => function ($request) {
            expect($request['context_chunks'])->not->toBeEmpty()
                ->and($request['context_chunks'][0]['content'])->toContain('5-7 business days');

            return Http::response([
                'body' => 'Grounded: shipping takes 5-7 business days.',
                'provider' => 'fake', 'model' => 'fake-1', 'input_tokens' => 1, 'output_tokens' => 1,
            ]);
        },
        '*' => Http::response(['status' => 'broadcast']),
    ]);

    $visitorToken = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->json('token');
    $conversationId = $this->withToken($visitorToken)
        ->postJson('/api/widget/conversations', [])->json('conversation_id');

    $this->withToken($visitorToken)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'How long does shipping to Canada take?',
    ])->assertCreated();

    $messages = $this->withToken($visitorToken)
        ->getJson("/api/widget/conversations/{$conversationId}/messages")
        ->json('data');

    expect(end($messages)['sender_type'])->toBe('chatbot')
        ->and(end($messages)['body'])->toContain('Grounded');
});
