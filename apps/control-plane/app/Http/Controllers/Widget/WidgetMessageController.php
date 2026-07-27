<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateChatbotReply;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Visitor;
use App\Services\ConversationMessenger;
use App\Services\ReactionToggler;
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
            ->with(['attachments', 'replyTo', 'reactions'])
            ->where('conversation_id', $conversation->id)
            ->where('sequence_number', '>', $validated['after_sequence'] ?? 0)
            ->orderBy('sequence_number')
            ->limit($validated['limit'] ?? 50)
            ->get();

        // Phase 28: reaction summaries for the whole recent window — the
        // widget only fetches NEW messages, so reactions landing on already
        // -seen messages ride this map instead.
        $reactionMap = MessageReaction::query()
            ->whereIn(
                'message_id',
                Message::query()->where('conversation_id', $conversation->id)
                    ->orderByDesc('sequence_number')->limit(50)->select('id'),
            )
            ->get()
            ->groupBy('message_id')
            ->map(
                fn ($group) => $group->groupBy('emoji')
                    ->map(fn ($emojiGroup, string $emoji): array => ['emoji' => $emoji, 'count' => $emojiGroup->count()])
                    ->values()
                    ->all(),
            );

        return response()->json([
            'data' => $messages->map(fn (Message $message): array => $message->toContract())->all(),
            'last_sequence' => $conversation->last_sequence,
            // Phase 23: piggyback conversation status on the poll the widget
            // already makes, so closure (→ CSAT prompt) needs no extra request.
            'status' => $conversation->status,
            'reactions' => (object) $reactionMap->all(),
        ]);
    }

    /** Toggle the visitor's reaction on a message (Phase 28). */
    public function react(
        Request $request,
        ReactionToggler $reactions,
        string $conversationId,
        string $messageId,
    ): JsonResponse {
        $conversation = $this->ownedConversation($request, $conversationId);

        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:16'],
        ]);

        $message = Message::query()->whereKey($messageId)
            ->where('conversation_id', $conversation->id)->first();
        abort_if($message === null, 404);

        return response()->json([
            'message_id' => $message->id,
            'reactions' => $reactions->toggle($message, 'visitor', $this->visitor($request)->id, $validated['emoji']),
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
            'reply_to_message_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        // A quote must reference a message of THIS conversation (Phase 28).
        $replyTo = $validated['reply_to_message_id'] ?? null;
        if (is_string($replyTo)) {
            abort_unless(
                Message::query()->whereKey($replyTo)->where('conversation_id', $conversation->id)->exists(),
                422,
                'reply_to_message_id must reference a message in this conversation.',
            );
        }

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
            replyToMessageId: is_string($replyTo) ? $replyTo : null,
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
