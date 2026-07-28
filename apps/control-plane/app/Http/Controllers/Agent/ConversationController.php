<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhooks;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Models\Visitor;
use App\Services\AssignmentService;
use App\Services\ConversationMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            'tag' => ['sometimes', 'string', 'max:30'],
            'channel' => ['sometimes', 'in:web,whatsapp,telegram,messenger'],
            'search' => ['sometimes', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $validated['status'] ?? 'all';
        $search = isset($validated['search']) ? trim($validated['search']) : '';

        $conversations = Conversation::query()
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when(
                isset($validated['department_id']),
                fn ($query) => $query->where('department_id', $validated['department_id']),
            )
            ->when(
                isset($validated['tag']),
                fn ($query) => $query->whereJsonContains('tags', $validated['tag']),
            )
            // Channel filter (Phase 33): "web" means no channel row.
            ->when(($validated['channel'] ?? null) === 'web', fn ($query) => $query->whereNull('channel_id'))
            ->when(
                in_array($validated['channel'] ?? null, ['whatsapp', 'telegram', 'messenger'], true),
                fn ($query) => $query->whereHas(
                    'channel',
                    fn ($sub) => $sub->where('type', $validated['channel']),
                ),
            )
            // Search (Phase 33): participant names OR message bodies.
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $query->where(function ($outer) use ($like): void {
                    $outer
                        ->whereHas('contact', fn ($sub) => $sub->where('name', 'like', $like))
                        ->orWhereHas('visitor', fn ($sub) => $sub->where('display_name', 'like', $like))
                        ->orWhereHas('messages', fn ($sub) => $sub->where('body', 'like', $like));
                });
            })
            ->with(['visitor', 'contact', 'department', 'assignedAgent:id,name', 'channel:id,type,name'])
            ->orderByDesc('updated_at')
            ->limit($validated['limit'] ?? 50)
            ->get();

        // Last-message previews: the latest message sits at the conversation's
        // last_sequence, so match on (conversation_id, sequence_number) — no
        // MAX() aggregate on the UUID key (which Postgres rejects).
        $latestMessages = collect();
        if ($conversations->isNotEmpty()) {
            $latestMessages = Message::query()
                ->where(function ($query) use ($conversations): void {
                    foreach ($conversations as $conversation) {
                        $query->orWhere(function ($sub) use ($conversation): void {
                            $sub->where('conversation_id', $conversation->id)
                                ->where('sequence_number', $conversation->last_sequence);
                        });
                    }
                })
                ->get()
                ->keyBy('conversation_id');
        }

        // Per-agent unread counts (Phase 33): one query for all rows.
        $user = $request->user();
        $reads = $user instanceof User
            ? ConversationRead::query()
                ->whereIn('conversation_id', $conversations->pluck('id')->all())
                ->where('user_id', $user->id)
                ->pluck('last_read_sequence', 'conversation_id')
            : collect();

        return response()->json([
            'data' => $conversations->map(function (Conversation $conversation) use ($reads, $latestMessages): array {
                $lastRead = $reads->get($conversation->id);
                $latest = $latestMessages->get($conversation->id);
                $latest = $latest instanceof Message ? $latest : null;

                return [
                    ...$this->toContract($conversation),
                    'unread_count' => max(
                        0,
                        (int) $conversation->last_sequence - (is_numeric($lastRead) ? (int) $lastRead : 0),
                    ),
                    'last_message' => $this->messagePreview($latest),
                    'last_message_sender' => $latest?->sender_type,
                ];
            })->all(),
        ]);
    }

    /** Advance the calling agent's read cursor (Phase 33). */
    public function markRead(Request $request, string $conversationId): JsonResponse
    {
        $conversation = Conversation::query()->find($conversationId);
        abort_if($conversation === null, 404);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        ConversationRead::query()->updateOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $user->id],
            ['last_read_sequence' => $conversation->last_sequence],
        );

        return response()->json(['last_read_sequence' => $conversation->last_sequence]);
    }

    public function show(string $conversationId): JsonResponse
    {
        $conversation = Conversation::query()->with(['visitor', 'contact', 'department', 'assignedAgent:id,name', 'channel:id,type,name'])->find($conversationId);
        abort_if($conversation === null, 404);

        return response()->json($this->toContract($conversation));
    }

    /**
     * Agent-initiated (proactive) conversation from the live visitor board
     * (Phase 24). The opening message is sent as the acting agent; the widget
     * adopts the thread on its next heartbeat. Reuses the visitor's existing
     * non-closed conversation instead of stacking a duplicate.
     */
    public function store(Request $request, ConversationMessenger $messenger): JsonResponse
    {
        $validated = $request->validate([
            'visitor_id' => ['required', 'uuid'],
            'message' => ['required', 'string', 'max:16000'],
        ]);

        $agent = $request->user();
        abort_unless($agent instanceof User, 403);

        // Org-scoped lookup: a foreign visitor id 404s (no existence leak).
        $visitor = Visitor::query()->whereKey($validated['visitor_id'])->first();
        abort_if($visitor === null, 404);

        $conversation = Conversation::query()
            ->where('visitor_id', $visitor->id)
            ->where('status', '!=', 'closed')
            ->latest('created_at')
            ->first();

        if ($conversation === null) {
            $conversation = Conversation::query()->create([
                'visitor_id' => $visitor->id,
                'contact_id' => $visitor->contact_id,
                'department_id' => Department::query()->where('is_default', true)->first()?->id,
                'assigned_agent_id' => $agent->id, // the initiator owns it
            ]);
        }

        $messenger->send(
            conversation: $conversation,
            senderType: 'agent',
            senderId: $agent->id,
            body: $validated['message'],
            idempotencyKey: (string) Str::uuid7(),
        );

        return response()->json(
            $this->toContract($conversation->fresh(['visitor', 'contact', 'department', 'assignedAgent:id,name', 'channel:id,type,name']) ?? $conversation),
            201,
        );
    }

    public function update(Request $request, string $conversationId, AssignmentService $assignments): JsonResponse
    {
        $conversation = Conversation::query()->find($conversationId);
        abort_if($conversation === null, 404);

        $validated = $request->validate([
            'status' => ['sometimes', 'in:open,pending,closed'],
            'assigned_agent_id' => ['sometimes', 'nullable', 'uuid'],
            'department_id' => ['sometimes', 'uuid'],
            'tags' => ['sometimes', 'array', 'max:10'],
            // nullable: the global middleware converts whitespace-only
            // entries to null before validation — they're dropped below.
            'tags.*' => ['nullable', 'string', 'max:30', 'regex:/^[^,<>]+$/'],
        ]);

        $actorId = $request->user() instanceof User ? $request->user()->id : null;

        if (array_key_exists('status', $validated)) {
            $conversation->status = $validated['status'];
            $conversation->closed_at = $validated['status'] === 'closed' ? now() : null;

            if ($validated['status'] === 'closed') {
                // Customer webhooks (Phase 35).
                DeliverWebhooks::dispatch(
                    (string) $conversation->organization_id,
                    'conversation.closed',
                    ['conversation_id' => $conversation->id],
                )->afterCommit();
            }
        }

        if (array_key_exists('tags', $validated)) {
            // Normalized: deduped, null/empty entries dropped (Phase 25).
            // Trimming already happened in the global TrimStrings middleware.
            $conversation->tags = array_values(array_unique(array_filter(
                $validated['tags'],
                fn (mixed $tag): bool => is_string($tag) && $tag !== '',
            )));
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

        return response()->json($this->toContract($conversation->load(['visitor', 'contact', 'department', 'assignedAgent:id,name', 'channel:id,type,name'])));
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
        $conversation->load(['visitor', 'contact', 'department', 'assignedAgent:id,name', 'channel:id,type,name']);

        return response()->json($this->toContract($conversation));
    }

    private function orUnassigned(?string $_agentId): void
    {
        // autoAssign returns null when no agent was eligible; that is a valid
        // outcome (conversation waits in the department queue), not an error.
    }

    /** A short, plain-text preview of a message for the inbox list. */
    private function messagePreview(?Message $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $body = $message->body;
        if ($message->content_type === 'rich') {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && is_string($decoded['text'] ?? null)) {
                $body = $decoded['text'];
            }
        }

        return Str::limit(trim($body), 80);
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
            'channel_type' => $conversation->channel?->type,
            'csat_rating' => $conversation->csat_rating,
            'csat_comment' => $conversation->csat_comment,
            'tags' => $conversation->tags ?? [],
            'created_at' => $conversation->created_at?->toIso8601String(),
            'updated_at' => $conversation->updated_at?->toIso8601String(),
        ];
    }
}
