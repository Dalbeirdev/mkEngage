<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal notes on a conversation (agent-only). RLS scopes every query to
 * the caller's org; notes are never exposed on the visitor/widget surface.
 */
final class ConversationNoteController extends Controller
{
    public function index(string $conversationId): JsonResponse
    {
        $this->conversation($conversationId);

        $notes = ConversationNote::query()
            ->with('author:id,name')
            ->where('conversation_id', $conversationId)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $notes->map(fn (ConversationNote $note): array => $note->toContract())->all(),
        ]);
    }

    public function store(Request $request, string $conversationId): JsonResponse
    {
        $conversation = $this->conversation($conversationId);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:8000'],
        ]);

        $agent = $request->user();
        abort_unless($agent instanceof User, 403);

        $note = ConversationNote::query()->create([
            'conversation_id' => $conversation->id,
            'author_id' => $agent->id,
            'body' => $validated['body'],
        ]);

        return response()->json($note->load('author:id,name')->toContract(), 201);
    }

    private function conversation(string $conversationId): Conversation
    {
        $conversation = Conversation::query()->find($conversationId);
        abort_if($conversation === null, 404);

        return $conversation;
    }
}
