<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateChatbotReply;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Visitor;
use App\Services\ConversationMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST message path (§4 REST fallback; the Phoenix gateway later owns the
 * WebSocket hot path against the same tables, ADR-002).
 *
 * Persist-before-confirm (§27) holds trivially here: the 201 IS the durable
 * ack — sequence assigned under row lock, message row committed by the
 * request transaction (EstablishTenantContext) before the response leaves.
 * Duplicate idempotency keys return the ORIGINAL message with 200
 * (RULES-message-ordering #8).
 */
final class WidgetMessageController extends Controller
{
    public function index(Request $request, string $conversationId): JsonResponse
    {
        $conversation = $this->ownedConversation($request, $conversationId);

        $validated = $request->validate([
            'after_sequence' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $messages = Message::query()
            ->with('attachments')
            ->where('conversation_id', $conversation->id)
            ->where('sequence_number', '>', $validated['after_sequence'] ?? 0)
            ->orderBy('sequence_number')
            ->limit($validated['limit'] ?? 50)
            ->get();

        return response()->json([
            'data' => $messages->map(fn (Message $message): array => $message->toContract())->all(),
            'last_sequence' => $conversation->last_sequence,
            // Phase 23: piggyback conversation status on the poll the widget
            // already makes, so closure (→ CSAT prompt) needs no extra request.
            'status' => $conversation->status,
        ]);
    }

    public function store(
        Request $request,
        ConversationMessenger $messenger,
        string $conversationId,
    ): JsonResponse {
        $conversation = $this->ownedConversation($request, $conversationId);
        abort_if($conversation->status === 'closed', 409, 'Conversation is closed.');

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'content_type' => ['required', 'in:text'],
            'body' => ['required', 'string', 'max:16000'],
            'correlation_id' => ['sometimes', 'nullable', 'uuid'],
            'attachment_ids' => ['sometimes', 'array', 'max:'.config()->integer('attachments.max_per_message')],
            'attachment_ids.*' => ['uuid', 'distinct'],
        ]);

        $visitor = $this->visitor($request);

        /** @var list<string> $attachmentIds */
        $attachmentIds = array_values($validated['attachment_ids'] ?? []);
        $this->assertLinkable($conversation, $visitor->id, $attachmentIds);

        $result = $messenger->send(
            conversation: $conversation,
            senderType: 'visitor',
            senderId: $visitor->id,
            body: $validated['body'],
            idempotencyKey: $validated['idempotency_key'],
            contentType: $validated['content_type'],
            correlationId: $validated['correlation_id'] ?? null,
            attachmentIds: $attachmentIds,
        );

        if (! $result['duplicate'] && $conversation->chatbot_id !== null) {
            // §2: AI runs via queued dispatch, never in this request.
            GenerateChatbotReply::dispatch(
                (string) $conversation->organization_id,
                $conversation->id,
            )->afterCommit();
        }

        return response()->json(
            $result['message']->toContract(),
            $result['duplicate'] ? 200 : 201,
        );
    }

    /**
     * Only the uploading visitor may link their own unlinked, non-quarantined
     * attachments from this conversation (§14 tenant-aware authorization).
     *
     * @param  list<string>  $attachmentIds
     */
    private function assertLinkable(
        Conversation $conversation,
        string $visitorId,
        array $attachmentIds,
    ): void {
        if ($attachmentIds === []) {
            return;
        }

        $linkable = Attachment::query()
            ->whereIn('id', $attachmentIds)
            ->where('conversation_id', $conversation->id)
            ->where('uploader_type', 'visitor')
            ->where('uploader_id', $visitorId)
            ->whereNull('message_id')
            ->where('scan_status', '!=', Attachment::STATUS_QUARANTINED)
            ->count();

        abort_if($linkable !== count($attachmentIds), 422, 'One or more attachments are not linkable.');
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
}
