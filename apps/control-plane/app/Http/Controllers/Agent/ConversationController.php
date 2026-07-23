<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Department;
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
            ->with(['visitor', 'contact', 'department'])
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
        $conversation = Conversation::query()->with(['visitor', 'contact', 'department'])->find($conversationId);
        abort_if($conversation === null, 404);

        return response()->json($this->toContract($conversation));
    }

    public function update(Request $request, string $conversationId): JsonResponse
    {
        $conversation = Conversation::query()->find($conversationId);
        abort_if($conversation === null, 404);

        $validated = $request->validate([
            'status' => ['sometimes', 'in:open,pending,closed'],
            'assigned_agent_id' => ['sometimes', 'nullable', 'uuid'],
            'department_id' => ['sometimes', 'uuid'],
        ]);

        if (array_key_exists('status', $validated)) {
            $conversation->status = $validated['status'];
            $conversation->closed_at = $validated['status'] === 'closed' ? now() : null;
        }

        if (array_key_exists('assigned_agent_id', $validated)) {
            $conversation->assigned_agent_id = $validated['assigned_agent_id'];
        }

        if (array_key_exists('department_id', $validated)) {
            // Must be a department of THIS org (fail closed on foreign ids).
            abort_unless(
                Department::query()->whereKey($validated['department_id'])->exists(),
                422,
                'Unknown department.',
            );
            $conversation->department_id = $validated['department_id'];
        }

        $conversation->save();

        return response()->json($this->toContract($conversation->load(['visitor', 'contact', 'department'])));
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
            'department_id' => $conversation->department_id,
            'department_name' => $conversation->department?->name,
            'last_sequence' => $conversation->last_sequence,
            'source_url' => $conversation->source_url,
            'created_at' => $conversation->created_at?->toIso8601String(),
            'updated_at' => $conversation->updated_at?->toIso8601String(),
        ];
    }
}
