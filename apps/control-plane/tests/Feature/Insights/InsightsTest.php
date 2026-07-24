<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\Visitor;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seed an org with conversations + messages. Returns [org, agent token].
 *
 * @param  array{open?: int, closed?: int, visitorMsgs?: int, agentMsgs?: int, botMsgs?: int}  $counts
 */
function seedInsights(array $counts): array
{
    $org = Organization::factory()->create();

    app(Tenancy::class)->run($org->id, function () use ($org, $counts): void {
        User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'agent@insights.test',
            'password' => Hash::make('password'),
        ]);
        $dept = Department::query()->create(['name' => 'Support', 'is_default' => true]);
        $visitor = Visitor::query()->create(['organization_id' => $org->id, 'consent_state' => 'unknown']);

        $mk = function (string $status) use ($dept, $visitor): Conversation {
            return Conversation::query()->create([
                'status' => $status,
                'department_id' => $dept->id,
                'visitor_id' => $visitor->id,
                'closed_at' => $status === 'closed' ? now() : null,
            ]);
        };

        $conv = null;
        for ($i = 0; $i < ($counts['open'] ?? 0); $i++) {
            $conv = $mk('open');
        }
        for ($i = 0; $i < ($counts['closed'] ?? 0); $i++) {
            $conv = $mk('closed');
        }
        $conv ??= $mk('open');

        $seq = 0;
        $addMsg = function (string $sender) use ($conv, $visitor, &$seq): void {
            $seq++;
            Message::query()->create([
                'conversation_id' => $conv->id,
                'sender_type' => $sender,
                'sender_id' => $visitor->id,
                'sequence_number' => $seq,
                'content_type' => 'text',
                'body' => 'x',
                'lifecycle_state' => 'persisted',
                'idempotency_key' => (string) Str::uuid7(),
                'sent_at' => now(),
            ]);
        };
        for ($i = 0; $i < ($counts['visitorMsgs'] ?? 0); $i++) {
            $addMsg('visitor');
        }
        for ($i = 0; $i < ($counts['agentMsgs'] ?? 0); $i++) {
            $addMsg('agent');
        }
        for ($i = 0; $i < ($counts['botMsgs'] ?? 0); $i++) {
            $addMsg('chatbot');
        }
    });

    $token = test()->postJson('/api/auth/token', [
        'organization' => $org->slug, 'email' => 'agent@insights.test',
        'password' => 'password', 'device_name' => 'pest',
    ])->assertCreated()->json('token');

    return [$org, $token];
}

it('reports conversation, message, and resolution metrics for the org', function (): void {
    [, $token] = seedInsights(['open' => 3, 'closed' => 1, 'visitorMsgs' => 4, 'agentMsgs' => 3, 'botMsgs' => 1]);

    $res = $this->withToken($token)->getJson('/api/insights/overview')->assertOk();

    expect($res->json('conversations.total'))->toBe(4)
        ->and($res->json('conversations.open'))->toBe(3)
        ->and($res->json('conversations.closed'))->toBe(1)
        ->and($res->json('conversations.resolution_rate'))->toBe(0.25)
        ->and($res->json('messages.total'))->toBe(8)
        ->and($res->json('messages.by_sender.visitor'))->toBe(4)
        ->and($res->json('messages.by_sender.agent'))->toBe(3)
        ->and($res->json('messages.by_sender.chatbot'))->toBe(1)
        // bot / (bot + agent) = 1/4
        ->and($res->json('messages.automation_rate'))->toBe(0.25);
});

it('groups conversations by department', function (): void {
    [, $token] = seedInsights(['open' => 2]);

    $res = $this->withToken($token)->getJson('/api/insights/overview')->assertOk();

    $support = collect($res->json('by_department'))->firstWhere('department_name', 'Support');
    expect($support)->not->toBeNull()
        ->and($support['conversations'])->toBe(2);
});

it('returns a dense daily series across the requested range', function (): void {
    [, $token] = seedInsights(['open' => 1]);

    $res = $this->withToken($token)
        ->getJson('/api/insights/overview?from=2000-01-01&to=2000-01-07')->assertOk();

    // 7 days, all present (dense), all zero for this ancient range.
    expect($res->json('daily'))->toHaveCount(7)
        ->and($res->json('daily.0.date'))->toBe('2000-01-01')
        ->and($res->json('daily.0.conversations'))->toBe(0);
});

it('validates the date range', function (): void {
    [, $token] = seedInsights(['open' => 1]);

    $this->withToken($token)
        ->getJson('/api/insights/overview?from=2026-02-01&to=2026-01-01')
        ->assertStatus(422); // inverted range
});

it('NEVER leaks another tenant into the aggregates (RLS-scoped)', function (): void {
    // Org A: 5 open conversations. Org B: 2 open. B must only ever see its own 2.
    [, $tokenA] = seedInsights(['open' => 5, 'agentMsgs' => 10]);
    [, $tokenB] = seedInsights(['open' => 2, 'agentMsgs' => 1]);

    $a = $this->withToken($tokenA)->getJson('/api/insights/overview')->assertOk();
    $b = $this->withToken($tokenB)->getJson('/api/insights/overview')->assertOk();

    expect($a->json('conversations.total'))->toBe(5)
        ->and($a->json('messages.total'))->toBe(10)
        ->and($b->json('conversations.total'))->toBe(2)  // NOT 7
        ->and($b->json('messages.total'))->toBe(1);      // NOT 11
});

it('requires authentication', function (): void {
    $this->getJson('/api/insights/overview')->assertUnauthorized();
});
