<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\AssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agent conversation surface (OpenAPI /conversations). Tenant scope is
 * enforced by RLS + global scope; any active user of the organization may
 * currently view all conversations — department/assignment-based authorization
 * policies (§16 ABAC) arrive with the routing module.
 */
final class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'in:open,pending,closed,all'],
            'department_id' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $validated['status'] ?? 'all';

        $conversations = Conversation::query()
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when(
                isset($validated['department_id']),
                fn ($query) => $query->where('department_id', $validated['department_id']),
            )
            ->with(['visitor', 'contact', 'department', 'assignedAgent:id,name'])
            ->orderByDesc('updated_at')
            ->limit($validated['limit'] ?? 50)
            ->get();

        return response()->json([
            'data' => $conversations->map(
                fn (Conversation $conversation): array => $this->toContract($conversation),
            )->all(),
        ]);
    }

    public function show(string $conversationId): JsonResponse
    {
        $conversation = Conversation::query()->with(['visitor', 'contact', 'department', 'assignedAgent:id,name'])->find($conversationId);
        abort_if($conversation === null, 404);

        return response()->json($this->toContract($conversation));
    }

    public function update(Request $request, string $conversationId, AssignmentService $assignments): JsonResponse
    {
        $conversation = Conversation::query()->find($conversationId);
        abort_if($conversation === null, 404);

        $validated = $request->validate([
            'status' => ['sometimes', 'in:open,pending,closed'],
            'assigned_agent_id' => ['sometimes', 'nullable', 'uuid'],
            'department_id' => ['sometimes', 'uuid'],
        ]);

        $actorId = $request->user() instanceof User ? $request->user()->id : null;

        if (array_key_exists('status', $validated)) {
            $conversation->status = $validated['status'];
            $conversation->closed_at = $validated['status'] === 'closed' ? now() : null;
        }

        if (array_key_exists('department_id', $validated)) {
            // Must be a department of THIS org (fail closed on foreign ids).
            abort_unless(
                Department::query()->whereKey($validated['department_id'])->exists(),
                422,
                'Unknown department.',
            );
            $conversation->department_id = $validated['department_id'];
            // Transfer clears the old owner; the new department re-routes below.
            $conversation->assigned_agent_id = null;
        }

        $conversation->save();

        // Explicit assignment change takes precedence over transfer re-routing.
        if (array_key_exists('assigned_agent_id', $validated)) {
            if ($validated['assigned_agent_id'] === null) {
                $assignments->unassign($conversation, $actorId);
            } else {
                // Validated in-service: must be an active member of the dept.
                $assignments->assignTo($conversation, $validated['assigned_agent_id'], $actorId);
            }
        } elseif (array_key_exists('department_id', $validated)) {
            // Transferred into a new department → auto-assign a new owner.
            $assignments->autoAssign($conversation, $actorId);
        }

        return response()->json($this->toContract($conversation->load(['visitor', 'contact', 'department', 'assignedAgent:id,name'])));
    }

    /**
     * Assign a conversation: `{"assignee": "me" | "auto" | "<agent uuid>"}`.
     * "me" self-assigns the caller; "auto" re-runs the department strategy.
     */
    public function assign(Request $request, string $conversationId, AssignmentService $assignments): JsonResponse
    {
        $conversation = Conversation::query()->find($conversationId);
        abort_if($conversation === null, 404);

        $validated = $request->validate([
            'assignee' => ['required', 'string', 'max:64'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        match (true) {
            $validated['assignee'] === 'auto' => $this->orUnassigned($assignments->autoAssign($conversation, $actor->id)),
            $validated['assignee'] === 'me' => $assignments->assignTo($conversation, $actor->id, $actor->id),
            default => $assignments->assignTo($conversation, $validated['assignee'], $actor->id),
        };

        // The service mutated $conversation in place; reload its relations
        // (fresh assignment) without another find().
        $conversation->load(['visitor', 'contact', 'department', 'assignedAgent:id,name']);

        return response()->json($this->toContract($conversation));
    }

    private function orUnassigned(?string $_agentId): void
    {
        // autoAssign returns null when no agent was eligible; that is a valid
        // outcome (conversation waits in the department queue), not an error.
    }

    /** @return array<string, mixed> */
    private function toContract(Conversation $conversation): array
    {
        return [
            'conversation_id' => $conversation->id,
            'status' => $conversation->status,
            'visitor_id' => $conversation->visitor_id,
            'visitor_name' => $conversation->visitor?->display_name,
            'contact_id' => $conversation->contact_id,
            'contact_name' => $conversation->contact?->name,
            'contact_email' => $conversation->contact?->email,
            'assigned_agent_id' => $conversation->assigned_agent_id,
            'assigned_agent_name' => $conversation->assignedAgent?->name,
            'department_id' => $conversation->department_id,
            'department_name' => $conversation->department?->name,
            'last_sequence' => $conversation->last_sequence,
            'source_url' => $conversation->source_url,
            'created_at' => $conversation->created_at?->toIso8601String(),
            'updated_at' => $conversation->updated_at?->toIso8601String(),
        ];
    }
}
