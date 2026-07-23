<?php

declare(strict_types=1);

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Services\ConversationMessenger;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function chatbotFixture(bool $activeBot = true): array
{
    $organization = Organization::factory()->create();

    app(Tenancy::class)->run($organization->id, function () use ($activeBot): void {
        Chatbot::query()->create([
            'name' => 'Acme Bot',
            'status' => $activeBot ? 'active' : 'paused',
            'system_prompt' => 'Be terse.',
            'provider' => 'fake',
        ]);
    });

    $token = test()->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->assertCreated()->json('token');

    $conversationId = test()->withToken($token)
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    return [$organization, $token, $conversationId];
}

function fakeAiService(string $reply = 'Bot answer here.'): void
{
    Http::fake([
        '127.0.0.1:8100/v1/reply' => Http::response([
            'body' => $reply,
            'provider' => 'fake',
            'model' => 'fake-1',
            'input_tokens' => 10,
            'output_tokens' => 5,
        ]),
    ]);
}

it('auto-replies via the AI service when an active chatbot is attached', function (): void {
    fakeAiService('Hello from the bot!');
    [, $token, $conversationId] = chatbotFixture();

    $this->withToken($token)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Are you open on Sundays?',
    ])->assertCreated();

    $messages = $this->withToken($token)
        ->getJson("/api/widget/conversations/{$conversationId}/messages")
        ->assertOk()->json('data');

    expect($messages)->toHaveCount(2)
        ->and($messages[1]['sender_type'])->toBe('chatbot')
        ->and($messages[1]['body'])->toBe('Hello from the bot!')
        ->and($messages[1]['sequence_number'])->toBe(2);

    // The AI request carried history + prompt, with the internal token.
    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/v1/reply')
            && $request['system_prompt'] === 'Be terse.'
            && $request['history'][0]['body'] === 'Are you open on Sundays?'
            && $request->hasHeader('Authorization');
    });
});

it('does not reply when no chatbot is active', function (): void {
    fakeAiService();
    [, $token, $conversationId] = chatbotFixture(activeBot: false);

    $this->withToken($token)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Hello?',
    ])->assertCreated();

    Http::assertNothingSent();

    expect($this->withToken($token)
        ->getJson("/api/widget/conversations/{$conversationId}/messages")
        ->json('data'))->toHaveCount(1);
});

it('stays silent after an agent joins (human takeover)', function (): void {
    fakeAiService();
    [$organization, $token, $conversationId] = chatbotFixture();

    // Agent replies first (before any visitor message triggers the bot).
    app(Tenancy::class)->run($organization->id, function () use ($conversationId): void {
        $agent = User::factory()->create(['email' => 'takeover@agent.test']);
        $conversation = Conversation::query()->findOrFail($conversationId);
        app(ConversationMessenger::class)->send(
            conversation: $conversation,
            senderType: 'agent',
            senderId: $agent->id,
            body: 'Agent here, I have this one.',
            idempotencyKey: (string) Str::uuid7(),
        );
    });

    $this->withToken($token)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Thanks!',
    ])->assertCreated();

    Http::assertNothingSent();
});

it('duplicate visitor sends do not trigger a second bot reply', function (): void {
    fakeAiService();
    [, $token, $conversationId] = chatbotFixture();
    $key = (string) Str::uuid7();

    foreach ([201, 200] as $expected) {
        $this->withToken($token)->postJson("/api/widget/conversations/{$conversationId}/messages", [
            'idempotency_key' => $key,
            'content_type' => 'text',
            'body' => 'Once only',
        ])->assertStatus($expected);
    }

    Http::assertSentCount(1);
});

it('keeps chat working when the AI service is down', function (): void {
    Http::fake(['127.0.0.1:8100/*' => Http::response(null, 503)]);
    [, $token, $conversationId] = chatbotFixture();

    // Visitor send still acks despite the bot failing (RULES-failure-retry).
    $this->withToken($token)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Anyone?',
    ])->assertCreated();

    expect($this->withToken($token)
        ->getJson("/api/widget/conversations/{$conversationId}/messages")
        ->json('data'))->toHaveCount(1);
});
