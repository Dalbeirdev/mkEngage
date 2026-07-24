<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Services\AttachmentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Visitor attachment surface (§14): upload into an owned conversation
 * (born `pending`, scanned async) and mint short-lived download URLs —
 * `clean` files only; quarantined files are never served.
 */
final class WidgetAttachmentController extends Controller
{
    public function store(
        Request $request,
        AttachmentStore $store,
        string $conversationId,
    ): JsonResponse {
        $conversation = $this->ownedConversation($request, $conversationId);
        abort_if($conversation->status === 'closed', 409, 'Conversation is closed.');

        $file = $this->validatedUpload($request);

        $attachment = $store->store(
            conversation: $conversation,
            uploaderType: 'visitor',
            uploaderId: $this->visitor($request)->id,
            file: $file,
        );

        return response()->json($attachment->toContract(), 201);
    }

    public function download(
        Request $request,
        AttachmentStore $store,
        string $conversationId,
        string $attachmentId,
    ): JsonResponse {
        $conversation = $this->ownedConversation($request, $conversationId);

        $attachment = Attachment::query()
            ->whereKey($attachmentId)
            ->where('conversation_id', $conversation->id)
            ->first();

        abort_if($attachment === null, 404);
        abort_if($attachment->scan_status === Attachment::STATUS_QUARANTINED, 410, 'This file failed malware scanning.');
        abort_if($attachment->scan_status !== Attachment::STATUS_CLEAN, 409, 'This file is still being scanned.');

        return response()->json(['url' => $store->downloadUrl($attachment)]);
    }

    private function validatedUpload(Request $request): UploadedFile
    {
        $maxKb = (int) ceil(config()->integer('attachments.max_bytes') / 1024);

        $validated = $request->validate([
            'file' => ['required', 'file', "max:{$maxKb}"],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];

        // Server-side detection (finfo), not the client-supplied header (§14).
        abort_unless(
            in_array($file->getMimeType(), config()->array('attachments.allowed_content_types'), true),
            422,
            'This file type is not allowed.',
        );

        return $file;
    }

    private function visitor(Request $request): Visitor
    {
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
