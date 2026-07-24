<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ScanAttachment;
use App\Models\Attachment;
use App\Models\Conversation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Attachment mechanics (§14): tenant-scoped storage paths, server-side
 * content-type detection, SHA-256 checksums, quarantine-first scanning, and
 * short-lived download URLs. Clients never see storage credentials — an
 * S3-compatible disk hands out real pre-signed URLs; the local disk uses
 * Laravel signed routes with identical properties.
 */
final class AttachmentStore
{
    /** Persist the upload + its metadata row; scanning is queued (§2). */
    public function store(
        Conversation $conversation,
        string $uploaderType,
        string $uploaderId,
        UploadedFile $file,
    ): Attachment {
        $id = (string) Str::uuid7();

        // Tenant-specific path (§14): org/conversation prefixes make bucket
        // policies and lifecycle rules per-tenant enforceable.
        $path = sprintf(
            'org/%s/conv/%s/%s',
            $conversation->organization_id,
            $conversation->id,
            $id,
        );

        Storage::disk($this->disk())->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        $attachment = Attachment::query()->create([
            'id' => $id,
            'organization_id' => $conversation->organization_id,
            'conversation_id' => $conversation->id,
            'uploader_type' => $uploaderType,
            'uploader_id' => $uploaderId,
            'file_name' => $this->sanitizeFileName($file->getClientOriginalName()),
            'content_type' => (string) $file->getMimeType(), // server-side detection, not the client header
            'size_bytes' => (int) $file->getSize(),
            'checksum_sha256' => hash_file('sha256', $file->getRealPath()) ?: '',
            'storage_path' => $path,
            'scan_status' => Attachment::STATUS_PENDING,
        ]);

        ScanAttachment::dispatch((string) $conversation->organization_id, $attachment->id)
            ->afterCommit();

        return $attachment;
    }

    /** Short-lived download URL — pre-signed (S3) or signed route (local). */
    public function downloadUrl(Attachment $attachment): string
    {
        $disk = Storage::disk($this->disk());
        $expires = now()->addSeconds(config()->integer('attachments.download_url_ttl'));

        if ($disk->providesTemporaryUrls()) {
            return $disk->temporaryUrl($attachment->storage_path, $expires, [
                'ResponseContentDisposition' => 'attachment; filename="'.addslashes($attachment->file_name).'"',
            ]);
        }

        // The signature carries authorization: it is only ever minted after
        // the caller proved conversation access, and it expires quickly.
        return URL::temporarySignedRoute('attachments.stream', $expires, [
            'attachment' => $attachment->id,
            'organization' => $attachment->organization_id,
        ]);
    }

    private function disk(): string
    {
        return config()->string('attachments.disk');
    }

    private function sanitizeFileName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $clean = (string) preg_replace('/[\x00-\x1f\x7f]/u', '', $base);

        return Str::limit($clean === '' ? 'file' : $clean, 250, '');
    }
}
