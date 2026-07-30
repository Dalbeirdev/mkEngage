<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * First-response SLA: per-priority minute targets, first_agent_reply_at
 * stamped by the first human reply, and due/breached computed on the
 * conversation contract.
 */

/** @return array{0: Organization, 1: string} [org, agent token] */
function slaOrg(): array
{
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org): void {
        User::factory()->create([
            'organization_id' => $org->id, 'email' => 'agent@sla.test',
            'password' => Hash::make('password'),
        ]);
    });
    auth()->forgetGuards();

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@sla.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

/** Create a widget conversation with one visitor message; returns its id. */
function slaConversation(Organization $org): string
{
    auth()->forgetGuards();
    $widgetToken = test()->postJson('/api/widget/session', [
        'site_key' => $org->fresh()?->widget_site_key,
    ])->assertCreated()->json('token');
    $conversationId = test()->withToken($widgetToken)
        ->postJson('/api/widget/conversations', [])->assertCreated()->json('conversation_id');
    test()->withToken($widgetToken)->postJson("/api/widget/conversations/{$conversationId}/messages", [
        'idempotency_key' => (string) Str::uuid7(), 'content_type' => 'text', 'body' => 'Help!',
    ])->assertCreated();
    auth()->forgetGuards();

    return $conversationId;
}

it('round-trips the SLA config', function (): void {
    [, $token] = slaOrg();

    test()->withToken($token)->getJson('/api/organization/sla')
        ->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('targets.urgent', null);

    test()->withToken($token)->putJson('/api/organization/sla', [
        'enabled' => true,
        'targets' => ['urgent' => 15, 'high' => 60, 'normal' => 240, 'low' => null],
    ])->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('targets.urgent', 15)
        ->assertJsonPath('targets.low', null);

    test()->withToken($token)->putJson('/api/organization/sla', [
        'enabled' => true, 'targets' => ['urgent' => 0],
    ])->assertStatus(422);
});

it('flags a breach only while awaiting the first human reply past the target', function (): void {
    [$org, $token] = slaOrg();

    test()->withToken($token)->putJson('/api/organization/sla', [
        'enabled' => true, 'targets' => ['urgent' => 15, 'high' => null, 'normal' => 240, 'low' => null],
    ])->assertOk();

    $id = slaConversation($org);
    test()->withToken($token)->patchJson("/api/conversations/{$id}", ['priority' => 'urgent'])->assertOk();

    // Fresh conversation: due in 15m, not breached yet.
    $fresh = test()->withToken($token)->getJson("/api/conversations/{$id}")->assertOk();
    expect($fresh->json('sla_breached'))->toBeFalse()
        ->and($fresh->json('sla_due_at'))->not->toBeNull();

    // Backdate the conversation past the target → breached.
    app(Tenancy::class)->run($org->id, function () use ($id): void {
        $conversation = Conversation::query()->findOrFail($id);
        $conversation->created_at = now()->subMinutes(30);
        $conversation->save();
    });
    expect(test()->withToken($token)->getJson("/api/conversations/{$id}")->json('sla_breached'))->toBeTrue();

    // An agent reply stamps first_agent_reply_at and clears the breach flag.
    test()->withToken($token)->postJson("/api/conversations/{$id}/messages", [
        'idempotency_key' => (string) Str::uuid7(), 'content_type' => 'text', 'body' => 'On it!',
    ])->assertCreated();

    $after = test()->withToken($token)->getJson("/api/conversations/{$id}")->assertOk();
    expect($after->json('sla_breached'))->toBeFalse()
        ->and($after->json('first_agent_reply_at'))->not->toBeNull();
});

it('reports no SLA when disabled or the priority has no target', function (): void {
    [$org, $token] = slaOrg();
    $id = slaConversation($org);

    // Disabled → null due date even with a priority set.
    $off = test()->withToken($token)->getJson("/api/conversations/{$id}")->assertOk();
    expect($off->json('sla_due_at'))->toBeNull()->and($off->json('sla_breached'))->toBeFalse();

    // Enabled but this priority (normal) has no target.
    test()->withToken($token)->putJson('/api/organization/sla', [
        'enabled' => true, 'targets' => ['urgent' => 15, 'high' => null, 'normal' => null, 'low' => null],
    ])->assertOk();
    expect(test()->withToken($token)->getJson("/api/conversations/{$id}")->json('sla_due_at'))->toBeNull();
});
