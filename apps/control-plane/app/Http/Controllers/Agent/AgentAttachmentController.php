<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AttachmentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Agent attachment surface (§14): agents upload into any org conversation
 * (RLS scopes the org) and can fetch download URLs for both sides' files —
 * `clean` only, exactly like the widget path.
 */
final class AgentAttachmentController extends Controller
{
    public function store(
        Request $request,
        AttachmentStore $store,
        string $conversationId,
    ): JsonResponse {
        $conversation = $this->conversation($conversationId);
        abort_if($conversation->status === 'closed', 409, 'Conversation is closed.');

        $agent = $request->user();
        abort_unless($agent instanceof User, 403);

        $file = $this->validatedUpload($request);

        $attachment = $store->store(
            conversation: $conversation,
            uploaderType: 'user',
            uploaderId: $agent->id,
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
        $conversation = $this->conversation($conversationId);

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

        abort_unless(
            in_array($file->getMimeType(), config()->array('attachments.allowed_content_types'), true),
            422,
            'This file type is not allowed.',
        );

        return $file;
    }

    private function conversation(string $conversationId): Conversation
    {
        $conversation = Conversation::query()->find($conversationId);
        abort_if($conversation === null, 404);

        return $conversation;
    }
}
