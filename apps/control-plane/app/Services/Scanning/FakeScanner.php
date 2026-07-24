<?php

declare(strict_types=1);

namespace App\Services\Scanning;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

/**
 * Deterministic scanner for local/CI: flags a project-specific marker string,
 * passes everything else — making the quarantine path end-to-end testable
 * without a real engine. Deliberately NOT the EICAR string: host antivirus
 * (Windows Defender, incl. CI runners) deletes EICAR files the moment they
 * touch disk, which breaks the very tests the marker exists for.
 */
final class FakeScanner implements MalwareScanner
{
    public const MARKER = 'MKENGAGE-FAKE-MALWARE-TEST-MARKER';

    public function scan(Attachment $attachment): string
    {
        $contents = Storage::disk(config()->string('attachments.disk'))
            ->get($attachment->storage_path);

        if ($contents !== null && str_contains($contents, self::MARKER)) {
            return self::QUARANTINED;
        }

        return self::CLEAN;
    }
}
