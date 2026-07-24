<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Signed download stream — the local-disk stand-in for S3 pre-signed URLs
 * (§14). Authorization is the signature itself: URLs are minted only after
 * the caller proved conversation access, expire quickly, and pin one
 * attachment + organization. The `signed` middleware rejects tampering.
 */
final class AttachmentStreamController extends Controller
{
    public function __invoke(Request $request, Tenancy $tenancy, string $attachmentId): StreamedResponse
    {
        $organizationId = $request->query('organization');
        abort_unless(is_string($organizationId) && $organizationId !== '', 403);

        return $tenancy->run($organizationId, function () use ($attachmentId): StreamedResponse {
            $attachment = Attachment::query()->find($attachmentId);

            abort_if($attachment === null, 404);
            // Quarantine is enforced at serve time too — a URL minted just
            // before a verdict cannot leak an infected file.
            abort_unless($attachment->scan_status === Attachment::STATUS_CLEAN, 410);

            $disk = Storage::disk(config()->string('attachments.disk'));
            abort_unless($disk->exists($attachment->storage_path), 404);

            return $disk->download(
                $attachment->storage_path,
                $attachment->file_name,
                ['Content-Type' => $attachment->content_type, 'X-Content-Type-Options' => 'nosniff'],
            );
        });
    }
}
