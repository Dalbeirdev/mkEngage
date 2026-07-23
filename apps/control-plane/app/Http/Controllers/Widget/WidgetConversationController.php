<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Visitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visitor-facing conversation endpoints. Tenant scope is already enforced
 * (RLS + global scope via EstablishTenantContext); these additionally
 * enforce VISITOR ownership — a visitor sees only their own conversations,
 * 404 otherwise (no existence leak, RULES-tenant-isolation #4).
 */
final class WidgetConversationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $visitor = $this->visitor($request);

        $validated = $request->validate([
            'source_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ]);

        $chatbot = Chatbot::query()->where('status', 'active')->first();
        $defaultDepartment = Department::query()->where('is_default', true)->first();

        $conversation = Conversation::query()->create([
            'visitor_id' => $visitor->id,
            'contact_id' => $visitor->contact_id,
            'chatbot_id' => $chatbot?->id,
            'department_id' => $defaultDepartment?->id, // routing v1 (A5)
            'source_url' => $validated['source_url'] ?? null,
        ]);

        return response()->json($this->toContract($conversation), 201);
    }

    public function show(Request $request, string $conversationId): JsonResponse
    {
        $conversation = $this->ownedConversation($request, $conversationId);

        return response()->json($this->toContract($conversation));
    }

    private function visitor(Request $request): Visitor
    {
        // The `widget` guard is provider-restricted to Visitor (config/auth.php);
        // a null principal here means the middleware stack was misconfigured.
        $principal = $request->user('widget');
        abort_unless($principal instanceof Visitor, 403);

        return $principal;
    }

    private function ownedConversation(Request $request, string $conversationId): Conversation
    {
        $conversation = Conversation::query()
            ->whereKey($conversationId)
            ->where('visitor_id', $this->visitor($request)->id)
            ->first();

        abort_if($conversation === null, 404);

        return $conversation;
    }

    /** @return array<string, mixed> */
    private function toContract(Conversation $conversation): array
    {
        return [
            'conversation_id' => $conversation->id,
            'status' => $conversation->status,
            'last_sequence' => $conversation->last_sequence,
            'created_at' => $conversation->created_at?->toIso8601String(),
        ];
    }
}
