<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Models\Visitor;
use App\Tenancy\Tenancy;

/**
 * Agent-to-agent transfer: a live handoff that reassigns the conversation to a
 * colleague AND records an internal note with the reason — distinct from a
 * plain reassign, and tenant-scoped.
 *
 * @return array{0: Organization, 1: Conversation, 2: list<User>, 3: string}
 */
function transferSetup(): array
{
    $org = Organization::factory()->create();

    [$conversation, $agents] = app(Tenancy::class)->run($org->id, function () use ($org): array {
        $dept = Department::query()->create(['name' => 'Support', 'is_default' => true, 'assignment_strategy' => 'manual']);

        $agents = [];
        foreach (['Ada Sender', 'Ben Receiver'] as $i => $name) {
            $agents[] = User::factory()->create([
                'organization_id' => $org->id,
                'name' => $name,
                'email' => "agent{$i}@transfer.test",
                'availability' => 'available',
                'status' => 'active',
            ]);
        }
        $dept->users()->sync(collect($agents)->mapWithKeys(fn (User $u) => [$u->id => ['organization_id' => $org->id]])->all());

        $conversation = Conversation::query()->create([
            'status' => 'open',
            'department_id' => $dept->id,
            'assigned_agent_id' => $agents[0]->id, // Ada owns it
            'visitor_id' => Visitor::query()->create(['organization_id' => $org->id, 'consent_state' => 'unknown'])->id,
        ]);

        return [$conversation, $agents];
    });

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug,
        'email' => 'agent0@transfer.test',
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $conversation, $agents, $token];
}

it('transfers a conversation to a colleague and records a handoff note', function (): void {
    [$org, $conversation, $agents, $token] = transferSetup();

    $this->withToken($token)->postJson("/api/conversations/{$conversation->id}/transfer", [
        'to_agent_id' => $agents[1]->id,
        'note' => 'Billing sorted, over to you for the refund.',
    ])->assertOk()->assertJsonPath('assigned_agent_id', $agents[1]->id);

    app(Tenancy::class)->run($org->id, function () use ($conversation, $agents): void {
        expect($conversation->fresh()->assigned_agent_id)->toBe($agents[1]->id);

        $note = ConversationNote::query()->where('conversation_id', $conversation->id)->latest('created_at')->first();
        expect($note)->not->toBeNull()
            ->and($note->author_id)->toBe($agents[0]->id) // authored by the sender
            ->and($note->body)->toContain('Transferred to Ben Receiver')
            ->and($note->body)->toContain('Billing sorted');
    });
});

it('transfers without a note', function (): void {
    [$org, $conversation, $agents, $token] = transferSetup();

    $this->withToken($token)->postJson("/api/conversations/{$conversation->id}/transfer", [
        'to_agent_id' => $agents[1]->id,
    ])->assertOk();

    app(Tenancy::class)->run($org->id, function () use ($conversation): void {
        $note = ConversationNote::query()->where('conversation_id', $conversation->id)->latest('created_at')->first();
        expect($note->body)->toBe('Transferred to Ben Receiver');
    });
});

it('rejects a transfer to an agent outside the conversation department', function (): void {
    [$org, $conversation, , $token] = transferSetup();

    // An active org user who is NOT a member of the Support department.
    $outsider = app(Tenancy::class)->run($org->id, fn () => User::factory()->create([
        'organization_id' => $org->id, 'status' => 'active',
    ]));

    $this->withToken($token)->postJson("/api/conversations/{$conversation->id}/transfer", [
        'to_agent_id' => $outsider->id,
    ])->assertStatus(422);
});

it('requires authentication', function (): void {
    [, $conversation, $agents] = transferSetup();

    $this->postJson("/api/conversations/{$conversation->id}/transfer", ['to_agent_id' => $agents[1]->id])
        ->assertUnauthorized();
});
