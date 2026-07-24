<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Models\Visitor;
use App\Services\AssignmentService;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @return array{0: Organization, 1: Department, 2: list<User>}
 */
function deptWith(string $strategy, int $agents, array $overrides = []): array
{
    $org = Organization::factory()->create();

    return app(Tenancy::class)->run($org->id, function () use ($org, $strategy, $agents, $overrides) {
        $dept = Department::query()->create([
            'name' => 'Support',
            'is_default' => true,
            'assignment_strategy' => $strategy,
        ]);

        $users = [];
        for ($i = 0; $i < $agents; $i++) {
            $users[] = User::factory()->create([
                'organization_id' => $org->id,
                'name' => "Agent {$i}",
                'availability' => $overrides['availability'][$i] ?? 'available',
                'max_open_conversations' => $overrides['cap'][$i] ?? null,
                'status' => $overrides['status'][$i] ?? 'active',
            ]);
        }
        $dept->users()->sync(
            collect($users)->mapWithKeys(fn (User $u) => [$u->id => ['organization_id' => $org->id]])->all()
        );

        return [$org, $dept, $users];
    });
}

function makeConversation(Organization $org, Department $dept): Conversation
{
    return app(Tenancy::class)->run($org->id, fn () => Conversation::query()->create([
        'status' => 'open',
        'department_id' => $dept->id,
        'visitor_id' => Visitor::query()->create([
            'organization_id' => $org->id, 'consent_state' => 'unknown',
        ])->id,
    ]));
}

it('least_busy assigns the agent with the fewest open conversations', function (): void {
    [$org, $dept, $agents] = deptWith('least_busy', 2);

    app(Tenancy::class)->run($org->id, function () use ($org, $dept, $agents): void {
        $service = app(AssignmentService::class);

        // Give agent 0 an existing open conversation → agent 1 is less busy.
        Conversation::query()->create([
            'status' => 'open', 'department_id' => $dept->id,
            'assigned_agent_id' => $agents[0]->id,
            'visitor_id' => Visitor::query()->create(['organization_id' => $org->id, 'consent_state' => 'unknown'])->id,
        ]);

        $conversation = makeConversation($org, $dept);
        $chosen = $service->autoAssign($conversation);

        expect($chosen)->toBe($agents[1]->id)
            ->and($conversation->fresh()->assigned_agent_id)->toBe($agents[1]->id);
    });
});

it('round_robin cycles through agents by last-assigned time', function (): void {
    [$org, $dept, $agents] = deptWith('round_robin', 3);

    app(Tenancy::class)->run($org->id, function () use ($org, $dept, $agents): void {
        $service = app(AssignmentService::class);
        $picked = [];
        for ($i = 0; $i < 3; $i++) {
            $picked[] = $service->autoAssign(makeConversation($org, $dept));
        }

        // Each of the three never-assigned agents is chosen exactly once.
        expect(array_unique($picked))->toHaveCount(3)
            ->and(collect($picked)->sort()->values()->all())
            ->toBe(collect($agents)->pluck('id')->sort()->values()->all());
    });
});

it('skips away agents and agents at their concurrency cap', function (): void {
    // Agent 0 away, agent 1 available with cap 1 (already full), agent 2 available.
    [$org, $dept, $agents] = deptWith('least_busy', 3, [
        'availability' => [0 => 'away', 1 => 'available', 2 => 'available'],
        'cap' => [1 => 1],
    ]);

    app(Tenancy::class)->run($org->id, function () use ($org, $dept, $agents): void {
        $service = app(AssignmentService::class);

        // Fill agent 1 to their cap.
        Conversation::query()->create([
            'status' => 'open', 'department_id' => $dept->id, 'assigned_agent_id' => $agents[1]->id,
            'visitor_id' => Visitor::query()->create(['organization_id' => $org->id, 'consent_state' => 'unknown'])->id,
        ]);

        $chosen = $service->autoAssign(makeConversation($org, $dept));
        expect($chosen)->toBe($agents[2]->id); // only eligible agent
    });
});

it('returns null (queued) when no agent is eligible', function (): void {
    [$org, $dept] = deptWith('least_busy', 1, ['availability' => [0 => 'away']]);

    app(Tenancy::class)->run($org->id, function () use ($org, $dept): void {
        $chosen = app(AssignmentService::class)->autoAssign(makeConversation($org, $dept));
        expect($chosen)->toBeNull();
    });
});

it('manual strategy never auto-assigns', function (): void {
    [$org, $dept] = deptWith('manual', 2);

    app(Tenancy::class)->run($org->id, function () use ($org, $dept): void {
        $conversation = makeConversation($org, $dept);
        expect(app(AssignmentService::class)->autoAssign($conversation))->toBeNull()
            ->and($conversation->fresh()->assigned_agent_id)->toBeNull();
    });
});

it('assignTo rejects an agent who is not a member of the department', function (): void {
    [$org, $dept] = deptWith('manual', 1);

    app(Tenancy::class)->run($org->id, function () use ($org, $dept): void {
        $outsider = User::factory()->create(['organization_id' => $org->id]);
        $conversation = makeConversation($org, $dept);

        expect(fn () => app(AssignmentService::class)->assignTo($conversation, $outsider->id))
            ->toThrow(HttpException::class);
    });
});

// ---- HTTP surface ----

function agentSession(): array
{
    Http::fake();
    $org = Organization::factory()->create();

    [$dept, $agent, $other] = app(Tenancy::class)->run($org->id, function () use ($org) {
        $dept = Department::query()->create(['name' => 'Support', 'is_default' => true, 'assignment_strategy' => 'manual']);
        $agent = User::factory()->create(['organization_id' => $org->id, 'email' => 'agent@a.test', 'password' => Hash::make('password')]);
        $other = User::factory()->create(['organization_id' => $org->id, 'email' => 'other@a.test', 'password' => Hash::make('password')]);
        $dept->users()->sync([$agent->id => ['organization_id' => $org->id]]);

        return [$dept, $agent, $other];
    });

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@a.test', 'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $dept, $agent, $other, $token];
}

it('lets an agent self-assign via POST /assign {assignee: me}', function (): void {
    [$org, $dept, $agent, , $token] = agentSession();
    $conversation = makeConversation($org, $dept);

    $res = test()->withToken($token)
        ->postJson("/api/conversations/{$conversation->id}/assign", ['assignee' => 'me'])
        ->assertOk();

    expect($res->json('assigned_agent_id'))->toBe($agent->id)
        ->and($res->json('assigned_agent_name'))->toBe($agent->name);
});

it('rejects assigning to a non-member agent (422, no cross-department leak)', function (): void {
    [$org, $dept, , $other, $token] = agentSession();
    $conversation = makeConversation($org, $dept);

    test()->withToken($token)
        ->postJson("/api/conversations/{$conversation->id}/assign", ['assignee' => $other->id])
        ->assertStatus(422);
});

it('toggles agent availability and excludes away agents from auto-assign', function (): void {
    [$org, $dept, $agent, , $token] = agentSession();

    test()->withToken($token)->patchJson('/api/me/availability', ['availability' => 'away'])
        ->assertOk()->assertJson(['availability' => 'away']);

    app(Tenancy::class)->run($org->id, function () use ($org, $dept): void {
        // Department is manual here; switch to least_busy to exercise auto-assign.
        $dept->update(['assignment_strategy' => 'least_busy']);
        $chosen = app(AssignmentService::class)->autoAssign(makeConversation($org, $dept));
        expect($chosen)->toBeNull(); // the only member is away
    });
});

it('records a data-minimized outbox event on assignment', function (): void {
    [$org, $dept, $agent] = agentSession();
    $conversation = makeConversation($org, $dept);

    app(Tenancy::class)->run($org->id, function () use ($conversation, $agent): void {
        app(AssignmentService::class)->assignTo($conversation, $agent->id, $agent->id);
    });

    $event = DB::table('outbox_events')
        ->where('event_type', 'conv.assignment.updated.v1')->first();
    $envelope = json_decode((string) $event->envelope, true);

    expect($envelope['data']['assigned_agent_id'])->toBe($agent->id)
        ->and($envelope['data']['action'])->toBe('assignment.assigned')
        ->and($envelope['data'])->not->toHaveKey('body');
});
