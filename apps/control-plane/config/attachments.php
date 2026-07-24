<?php

declare(strict_types=1);

/*
 * Chat attachments (§14). The disk is S3-compatible in production (pre-signed
 * URLs, encryption, lifecycle rules live at the bucket); local dev uses the
 * private "attachments" disk with Laravel signed routes standing in for
 * pre-signed URLs — same properties: short expiry, no storage credentials
 * ever reach a client.
 */
return [

    'disk' => env('ATTACHMENTS_DISK', 'attachments'),

    // Hard per-file cap, validated in-app (PHP's upload_max_filesize caps
    // below this in some environments; the app limit is the contract).
    'max_bytes' => (int) env('ATTACHMENTS_MAX_BYTES', 5 * 1024 * 1024),

    'max_per_message' => 5,

    // Content-type allowlist (§14 content-type validation). Extend per
    // tenant later; executables and scripts stay out by construction.
    'allowed_content_types' => [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
        'text/csv',
    ],

    // Seconds a signed download URL stays valid (§14 short expiration).
    'download_url_ttl' => (int) env('ATTACHMENTS_URL_TTL', 300),

    // Malware scanner binding: "fake" locally/CI; a real engine (e.g.
    // ClamAV sidecar) implements the same interface in production.
    'scanner' => env('ATTACHMENTS_SCANNER', 'fake'),

];
