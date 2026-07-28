<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Agent AI assist: a suggested reply (drafted by the AI service), a summary,
 * and a keyword sentiment over the visitor's messages.
 */

/** @return array{0: Organization, 1: string} */
function assistOrg(): array
{
    $org = Organization::factory()->create();
    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@assist.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();
    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@assist.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

it('returns an AI-drafted suggested reply plus summary and sentiment', function (): void {
    Http::fake(['*/v1/reply' => Http::response(['body' => 'Sure — here is our pricing page.'], 200)]);
    [$org, $token] = assistOrg();

    // A widget conversation with one (unhappy) visitor message.
    auth()->forgetGuards();
    $widgetToken = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated()->json('token');
    $conversationId = test()->withToken($widgetToken)
        ->postJson('/api/widget/conversations', [])->assertCreated()->json('conversation_id');
    test()->withToken($widgetToken)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'The checkout is broken and I want a refund',
    ])->assertCreated();

    $body = test()->withToken($token)->postJson("/api/conversations/{$conversationId}/assist")
        ->assertOk()->json();

    expect($body['suggested_reply'])->toBe('Sure — here is our pricing page.')
        ->and($body['sentiment'])->toBe('negative')
        ->and($body['summary'])->toContain('broken');
});

it('degrades gracefully when the AI service is unavailable', function (): void {
    Http::fake(['*/v1/reply' => Http::response('', 503)]);
    [$org, $token] = assistOrg();

    auth()->forgetGuards();
    $widgetToken = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated()->json('token');
    $conversationId = test()->withToken($widgetToken)
        ->postJson('/api/widget/conversations', [])->assertCreated()->json('conversation_id');
    test()->withToken($widgetToken)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(), 'content_type' => 'text', 'body' => 'thanks, great help',
    ])->assertCreated();

    $body = test()->withToken($token)->postJson("/api/conversations/{$conversationId}/assist")
        ->assertOk()->json();

    expect($body['suggested_reply'])->toBeNull()
        ->and($body['sentiment'])->toBe('positive');
});
