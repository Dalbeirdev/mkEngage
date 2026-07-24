<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Attachment;
use App\Services\Scanning\MalwareScanner;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asynchronous malware scan (§14): uploads are born `pending` and only a
 * verdict here makes them downloadable (`clean`) or dead (`quarantined`).
 * Runs under explicit tenant context — queue workers have no ambient org.
 */
final class ScanAttachment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $attachmentId,
    ) {}

    public function handle(MalwareScanner $scanner, Tenancy $tenancy): void
    {
        $tenancy->run($this->organizationId, function () use ($scanner): void {
            $attachment = Attachment::query()->find($this->attachmentId);

            if ($attachment === null || $attachment->scan_status !== Attachment::STATUS_PENDING) {
                return; // deleted or already scanned — idempotent re-runs
            }

            $attachment->update(['scan_status' => $scanner->scan($attachment)]);
        });
    }
}
