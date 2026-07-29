<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Slack integration: per-org incoming-webhook config (write-only URL) and a
 * new-conversation notification fired on the first customer message.
 */

/** @return array{0: Organization, 1: string} [org, agent token] */
function slackOrg(): array
{
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@slack.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@slack.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

it('configures Slack without ever returning the webhook URL', function (): void {
    [, $token] = slackOrg();

    test()->withToken($token)->getJson('/api/integrations/slack')
        ->assertOk()->assertJsonPath('slack.enabled', false)->assertJsonPath('slack.configured', false);

    test()->withToken($token)->putJson('/api/integrations/slack', [
        'enabled' => true,
        'webhook_url' => 'https://hooks.slack.com/services/T000/B000/xyz',
    ])->assertOk()
        ->assertJsonPath('slack.enabled', true)
        ->assertJsonPath('slack.configured', true)
        // write-only: the URL is never echoed back
        ->assertJsonMissingPath('slack.webhook_url');
});

it('rejects a non-https webhook URL', function (): void {
    [, $token] = slackOrg();

    test()->withToken($token)->putJson('/api/integrations/slack', [
        'enabled' => true, 'webhook_url' => 'http://insecure.test/hook',
    ])->assertStatus(422);
});

it('notifies Slack when a new conversation starts', function (): void {
    Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
    [$org, $token] = slackOrg();

    test()->withToken($token)->putJson('/api/integrations/slack', [
        'enabled' => true, 'webhook_url' => 'https://hooks.slack.com/services/T1/B1/secret',
    ])->assertOk();

    // A visitor opens a conversation and sends the first message.
    auth()->forgetGuards();
    $widgetToken = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated()->json('token');
    $conversationId = test()->withToken($widgetToken)
        ->postJson('/api/widget/conversations', [])->assertCreated()->json('conversation_id');
    test()->withToken($widgetToken)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'Hi, my order is late',
    ])->assertCreated();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'hooks.slack.com')
        && str_contains((string) ($request['text'] ?? ''), 'New conversation')
        && str_contains((string) ($request['text'] ?? ''), 'my order is late'));
});

it('does not notify Slack when the integration is disabled', function (): void {
    Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
    [$org] = slackOrg();

    auth()->forgetGuards();
    $widgetToken = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated()->json('token');
    $conversationId = test()->withToken($widgetToken)
        ->postJson('/api/widget/conversations', [])->assertCreated()->json('conversation_id');
    test()->withToken($widgetToken)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(),
        'content_type' => 'text',
        'body' => 'hello',
    ])->assertCreated();

    Http::assertNothingSent();
});

it('test endpoint 422s when Slack is not configured', function (): void {
    [, $token] = slackOrg();

    test()->withToken($token)->postJson('/api/integrations/slack/test')
        ->assertStatus(422)->assertJsonPath('reason', 'not_configured');
});
