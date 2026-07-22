<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Agent message history + reply (same mechanics as the widget path — §27). */
final class AgentMessageController extends Controller
{
    public function index(Request $request, string $conversationId): JsonResponse
    {
        $conversation = $this->conversation($conversationId);

        $validated = $request->validate([
            'after_sequence' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sequence_number', '>', $validated['after_sequence'] ?? 0)
            ->orderBy('sequence_number')
            ->limit($validated['limit'] ?? 100)
            ->get();

        return response()->json([
            'data' => $messages->map(fn (Message $message): array => $message->toContract())->all(),
            'last_sequence' => $conversation->last_sequence,
        ]);
    }

    public function store(
        Request $request,
        ConversationMessenger $messenger,
        string $conversationId,
    ): JsonResponse {
        $conversation = $this->conversation($conversationId);
        abort_if($conversation->status === 'closed', 409, 'Conversation is closed.');

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'content_type' => ['required', 'in:text'],
            'body' => ['required', 'string', 'max:16000'],
        ]);

        $agent = $request->user();
        abort_unless($agent instanceof User, 403);

        $result = $messenger->send(
            conversation: $conversation,
            senderType: 'agent',
            senderId: $agent->id,
            body: $validated['body'],
            idempotencyKey: $validated['idempotency_key'],
            contentType: $validated['content_type'],
        );

        return response()->json(
            $result['message']->toContract(),
            $result['duplicate'] ? 200 : 201,
        );
    }

    private function conversation(string $conversationId): Conversation
    {
        $conversation = Conversation::query()->find($conversationId);
        abort_if($conversation === null, 404);

        return $conversation;
    }
}
