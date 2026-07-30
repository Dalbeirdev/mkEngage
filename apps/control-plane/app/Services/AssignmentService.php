<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverWebhooks;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assignment routing v2 (§16): picks a human agent for a conversation within
 * its department, honouring the department's strategy, agent availability,
 * and per-agent concurrency caps.
 *
 * Candidates are always the ACTIVE + AVAILABLE members of the conversation's
 * department who are under their open-conversation cap. Strategy only decides
 * the ordering among candidates:
 *
 *  - round_robin: the member assigned longest ago (never-assigned first).
 *  - least_busy:  the member with the fewest open assigned conversations.
 *  - manual:      no automatic assignment (agents self-assign).
 *
 * All mutations run in the caller's transaction; RLS scopes every query.
 */
final class AssignmentService
{
    public function __construct(private readonly EventPublisher $events) {}

    /**
     * Auto-assign per the department strategy. Returns the chosen agent id,
     * or null when the strategy is manual, the conversation has no department,
     * or no eligible agent exists (it stays in the department queue).
     */
    public function autoAssign(Conversation $conversation, ?string $actorId = null): ?string
    {
        $department = $conversation->department_id === null
            ? null
            : Department::query()->find($conversation->department_id);

        if ($department === null || $department->assignment_strategy === 'manual') {
            return null;
        }

        $agentId = $this->pick($department, $conversation->assigned_agent_id);

        if ($agentId === null) {
            return null;
        }

        $this->apply($conversation, $agentId, $department, 'assignment.auto_assigned', $actorId);

        return $agentId;
    }

    /**
     * Explicitly assign to a specific agent (self-assign / manual reassign).
     * Validates the agent is an active member of the conversation's department;
     * throws HttpException(422) otherwise. Fail-closed against foreign ids.
     */
    public function assignTo(Conversation $conversation, string $agentId, ?string $actorId = null): void
    {
        $this->assignChecked($conversation, $agentId, 'assignment.assigned', $actorId);
    }

    /**
     * Agent-to-agent handoff: same validation and live-assignment event as
     * assignTo, but recorded as a transfer so the receiving agent's UI can
     * distinguish a deliberate handoff from a routing reassignment.
     */
    public function transfer(Conversation $conversation, string $agentId, ?string $actorId = null): void
    {
        $this->assignChecked($conversation, $agentId, 'assignment.transferred', $actorId);
    }

    private function assignChecked(Conversation $conversation, string $agentId, string $action, ?string $actorId): void
    {
        abort_if($conversation->department_id === null, 422, 'Conversation has no department.');

        $department = Department::query()->findOrFail($conversation->department_id);

        $isMember = $department->users()
            ->where('users.id', $agentId)
            ->where('users.status', 'active')
            ->exists();

        abort_unless($isMember, 422, 'Agent is not an active member of this department.');

        $this->apply($conversation, $agentId, $department, $action, $actorId);
    }

    /** Clear the assignment (return the conversation to the department queue). */
    public function unassign(Conversation $conversation, ?string $actorId = null): void
    {
        if ($conversation->assigned_agent_id === null) {
            return;
        }

        $conversation->assigned_agent_id = null;
        $conversation->save();

        $this->record($conversation, null, 'assignment.unassigned', $actorId);
    }

    /** @return string|null chosen agent id */
    private function pick(Department $department, ?string $currentAgentId): ?string
    {
        // Eligible = active + available department members under their cap.
        // Busy count and cap are computed per candidate in one pass.
        $members = $department->users()
            ->where('users.status', 'active')
            ->where('users.availability', 'available')
            ->get(['users.id', 'users.max_open_conversations', 'department_user.last_assigned_at']);

        if ($members->isEmpty()) {
            return null;
        }

        $memberIds = array_values(
            $members->pluck('id')->filter(static fn ($id): bool => is_string($id))->all()
        );
        $openCounts = $this->openConversationCounts($memberIds);

        $eligible = $members->filter(function (User $agent) use ($openCounts): bool {
            $cap = $agent->max_open_conversations;
            $open = $openCounts[$agent->id] ?? 0;

            return $cap === null || $open < $cap;
        });

        if ($eligible->isEmpty()) {
            return null;
        }

        return match ($department->assignment_strategy) {
            'round_robin' => $eligible
                // Never-assigned (null) first, then oldest last_assigned_at.
                // The pivot column is selected as a raw string alias, so parse it.
                ->sortBy(fn (User $a) => $this->assignedTimestamp($a->getAttribute('last_assigned_at')))
                ->first()?->id,
            default => $eligible // least_busy
                ->sortBy(fn (User $a) => $openCounts[$a->id] ?? 0)
                ->first()?->id,
        };
    }

    private function assignedTimestamp(mixed $lastAssignedAt): int
    {
        if (! is_string($lastAssignedAt) || $lastAssignedAt === '') {
            return 0; // never assigned — pick first
        }

        return Carbon::parse($lastAssignedAt)->getTimestamp();
    }

    /**
     * @param  list<string>  $agentIds
     * @return array<string, int> agent id => open assigned conversation count
     */
    private function openConversationCounts(array $agentIds): array
    {
        if ($agentIds === []) {
            return [];
        }

        $rows = DB::table('conversations')
            ->select('assigned_agent_id', DB::raw('count(*) as open_count'))
            ->whereIn('assigned_agent_id', $agentIds)
            ->where('status', '!=', 'closed')
            ->groupBy('assigned_agent_id')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $id = $row->assigned_agent_id;
            $open = $row->open_count;
            if (is_string($id) && is_numeric($open)) {
                $counts[$id] = (int) $open;
            }
        }

        return $counts;
    }

    private function apply(
        Conversation $conversation,
        string $agentId,
        Department $department,
        string $action,
        ?string $actorId,
    ): void {
        $conversation->assigned_agent_id = $agentId;
        $conversation->save();

        // Advance the round-robin cursor for this member in this department.
        $department->users()->updateExistingPivot($agentId, ['last_assigned_at' => now()]);

        $this->record($conversation, $agentId, $action, $actorId);
    }

    private function record(
        Conversation $conversation,
        ?string $agentId,
        string $action,
        ?string $actorId,
    ): void {
        $this->events->record(
            'conv.assignment.updated.v1',
            (string) $conversation->organization_id,
            [
                'conversation_id' => $conversation->id,
                'assigned_agent_id' => $agentId,
                'department_id' => $conversation->department_id,
                'action' => $action,
            ],
            $actorId,
        );

        // Customer webhooks (§15): mirror the internal event outward.
        DeliverWebhooks::dispatch(
            (string) $conversation->organization_id,
            'conversation.assigned',
            [
                'conversation_id' => $conversation->id,
                'assigned_agent_id' => $agentId,
                'action' => $action,
            ],
        )->afterCommit();
    }
}
