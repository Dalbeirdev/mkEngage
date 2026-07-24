<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
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
            ->with('attachments')
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
            'attachment_ids' => ['sometimes', 'array', 'max:'.config()->integer('attachments.max_per_message')],
            'attachment_ids.*' => ['uuid', 'distinct'],
        ]);

        $agent = $request->user();
        abort_unless($agent instanceof User, 403);

        /** @var list<string> $attachmentIds */
        $attachmentIds = array_values($validated['attachment_ids'] ?? []);
        $this->assertLinkable($conversation, 'user', $agent->id, $attachmentIds);

        $result = $messenger->send(
            conversation: $conversation,
            senderType: 'agent',
            senderId: $agent->id,
            body: $validated['body'],
            idempotencyKey: $validated['idempotency_key'],
            contentType: $validated['content_type'],
            attachmentIds: $attachmentIds,
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

    /**
     * Only the uploader may link their own unlinked, non-quarantined
     * attachments from this conversation (§14 tenant-aware authorization).
     *
     * @param  list<string>  $attachmentIds
     */
    private function assertLinkable(
        Conversation $conversation,
        string $uploaderType,
        string $uploaderId,
        array $attachmentIds,
    ): void {
        if ($attachmentIds === []) {
            return;
        }

        $linkable = Attachment::query()
            ->whereIn('id', $attachmentIds)
            ->where('conversation_id', $conversation->id)
            ->where('uploader_type', $uploaderType)
            ->where('uploader_id', $uploaderId)
            ->whereNull('message_id')
            ->where('scan_status', '!=', Attachment::STATUS_QUARANTINED)
            ->count();

        abort_if($linkable !== count($attachmentIds), 422, 'One or more attachments are not linkable.');
    }
}
