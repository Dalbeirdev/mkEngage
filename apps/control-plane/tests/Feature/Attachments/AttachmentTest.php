<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\Scanning\FakeScanner;
use App\Tenancy\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @return array{0: Organization, 1: string, 2: string} org, visitor token, conversation id */
function attachmentFixture(): array
{
    Http::fake();
    Storage::fake('attachments');
    $organization = Organization::factory()->create();

    $token = test()->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->assertCreated()->json('token');

    $conversationId = test()->withToken($token)
        ->postJson('/api/widget/conversations', [])
        ->assertCreated()->json('conversation_id');

    return [$organization, $token, $conversationId];
}

function agentToken(Organization $organization): string
{
    app(Tenancy::class)->run($organization->id, function (): void {
        User::factory()->create(['email' => 'agent@attachments.test']);
    });

    return test()->postJson('/api/auth/token', [
        'organization' => $organization->slug,
        'email' => 'agent@attachments.test',
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertCreated()->json('token');
}

it('uploads a visitor attachment: pending → scanned clean, checksummed, tenant-pathed', function (): void {
    [$organization, $token, $conversationId] = attachmentFixture();

    $upload = UploadedFile::fake()->createWithContent('notes.txt', 'meeting notes for support');

    $response = $this->withToken($token)
        ->post("/api/widget/conversations/{$conversationId}/attachments", ['file' => $upload])
        ->assertCreated();

    // Sync queue: the scan job ran at commit — clean by the time we look.
    $attachment = DB::table('attachments')->where('id', $response->json('attachment_id'))->first();

    expect($response->json('file_name'))->toBe('notes.txt')
        ->and($response->json('content_type_header'))->toContain('text/plain')
        ->and($attachment->scan_status)->toBe('clean')
        ->and($attachment->checksum_sha256)->toBe(hash('sha256', 'meeting notes for support'))
        ->and($attachment->storage_path)->toStartWith("org/{$organization->id}/conv/{$conversationId}/");

    Storage::disk('attachments')->assertExists($attachment->storage_path);
});

it('quarantines flagged uploads and refuses to serve or link them', function (): void {
    [, $token, $conversationId] = attachmentFixture();

    // Project marker, not EICAR: host AV (Defender) deletes real EICAR
    // files from temp before the request can even store them.
    $flagged = UploadedFile::fake()->createWithContent(
        'totally-fine.txt',
        'harmless preamble '.FakeScanner::MARKER,
    );

    $attachmentId = $this->withToken($token)
        ->post("/api/widget/conversations/{$conversationId}/attachments", ['file' => $flagged])
        ->assertCreated()->json('attachment_id');

    expect(DB::table('attachments')->where('id', $attachmentId)->value('scan_status'))
        ->toBe('quarantined');

    // Never downloadable (410 Gone), never linkable (422).
    $this->withToken($token)
        ->getJson("/api/widget/conversations/{$conversationId}/attachments/{$attachmentId}/download")
        ->assertStatus(410);

    $this->withToken($token)
        ->postJson("/api/widget/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'see attached',
            'attachment_ids' => [$attachmentId],
        ])->assertStatus(422);
});

it('rejects disallowed content types and oversized files', function (): void {
    [, $token, $conversationId] = attachmentFixture();

    // Executables are outside the allowlist. (Production detects the type
    // server-side via finfo; the fake upload guesses from the name.)
    $exe = UploadedFile::fake()->createWithContent('setup.exe', 'MZ fake binary');
    $this->withToken($token)
        ->post("/api/widget/conversations/{$conversationId}/attachments", ['file' => $exe])
        ->assertStatus(422);

    config()->set('attachments.max_bytes', 1024);
    $big = UploadedFile::fake()->createWithContent('big.txt', str_repeat('a', 2048));
    $this->withToken($token)->withHeader('Accept', 'application/json')
        ->post("/api/widget/conversations/{$conversationId}/attachments", ['file' => $big])
        ->assertStatus(422);
});

it("rejects uploads into another visitor's conversation without an existence oracle", function (): void {
    [$organization, , $conversationId] = attachmentFixture();

    $otherToken = $this->postJson('/api/widget/session', [
        'site_key' => $organization->widget_site_key,
    ])->json('token');

    // Sanctum caches the resolved principal per guard within one test.
    $this->app['auth']->forgetGuards();

    $this->withToken($otherToken)
        ->post("/api/widget/conversations/{$conversationId}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'hi'),
        ])->assertNotFound();
});

it('links attachments on send: message contract + outbox count carry them', function (): void {
    [, $token, $conversationId] = attachmentFixture();

    $attachmentId = $this->withToken($token)
        ->post("/api/widget/conversations/{$conversationId}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('report.txt', 'quarterly numbers'),
        ])->json('attachment_id');

    $sent = $this->withToken($token)
        ->postJson("/api/widget/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'Here is the report',
            'attachment_ids' => [$attachmentId],
        ])->assertCreated();

    expect($sent->json('attachments.0.attachment_id'))->toBe($attachmentId)
        ->and($sent->json('attachments.0.file_name'))->toBe('report.txt')
        ->and(DB::table('attachments')->where('id', $attachmentId)->value('message_id'))
        ->toBe($sent->json('message_id'));

    $envelope = json_decode(
        (string) DB::table('outbox_events')->where('event_type', 'conv.message.accepted.v1')->value('envelope'),
        true,
    );
    expect($envelope['data']['attachment_count'])->toBe(1);

    // Already linked ⇒ a second message cannot claim it.
    $this->withToken($token)
        ->postJson("/api/widget/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'again',
            'attachment_ids' => [$attachmentId],
        ])->assertStatus(422);
});

it('serves clean files through short-lived signed URLs and rejects tampering', function (): void {
    [, $token, $conversationId] = attachmentFixture();

    // Swap the Storage fake (which simulates pre-signed URLs) for a real
    // local disk in a scratch root — this test exercises the signed-route
    // fallback that local/dev deployments use.
    $scratch = sys_get_temp_dir().'/mkengage-att-'.uniqid();
    config()->set('filesystems.disks.attachments.root', $scratch);
    Storage::forgetDisk('attachments');

    $attachmentId = $this->withToken($token)
        ->post("/api/widget/conversations/{$conversationId}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('hello.txt', 'signed bytes'),
        ])->json('attachment_id');

    $url = $this->withToken($token)
        ->getJson("/api/widget/conversations/{$conversationId}/attachments/{$attachmentId}/download")
        ->assertOk()->json('url');

    expect($url)->toContain('signature=');

    // The signed URL streams the original bytes WITHOUT any auth token.
    $relative = (string) parse_url($url, PHP_URL_PATH).'?'.(string) parse_url($url, PHP_URL_QUERY);
    $this->get($relative)
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertStreamedContent('signed bytes');

    // Tampering with the query invalidates the signature.
    $this->get(str_replace('organization=', 'organization=0', $relative))->assertStatus(403);
});

it('lets agents upload and download across both sides of the conversation', function (): void {
    [$organization, $visitorToken, $conversationId] = attachmentFixture();

    $visitorAttachment = $this->withToken($visitorToken)
        ->post("/api/widget/conversations/{$conversationId}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('from-visitor.txt', 'visitor file'),
        ])->json('attachment_id');

    $token = agentToken($organization);

    // Agent downloads the visitor's file…
    $this->withToken($token)
        ->getJson("/api/conversations/{$conversationId}/attachments/{$visitorAttachment}/download")
        ->assertOk();

    // …and replies with their own.
    $agentAttachment = $this->withToken($token)
        ->post("/api/conversations/{$conversationId}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('from-agent.txt', 'agent file'),
        ])->assertCreated()->json('attachment_id');

    $sent = $this->withToken($token)
        ->postJson("/api/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'Attached for you',
            'attachment_ids' => [$agentAttachment],
        ])->assertCreated();

    expect($sent->json('attachments.0.attachment_id'))->toBe($agentAttachment);

    // The visitor sees the attachment in the message list…
    $listed = $this->withToken($visitorToken)
        ->getJson("/api/widget/conversations/{$conversationId}/messages")
        ->assertOk();

    $withAttachment = collect($listed->json('data'))->firstWhere('attachments.0.attachment_id', $agentAttachment);
    expect($withAttachment)->not->toBeNull();

    // …and can download it.
    $this->withToken($visitorToken)
        ->getJson("/api/widget/conversations/{$conversationId}/attachments/{$agentAttachment}/download")
        ->assertOk();
});

it('refuses to link an attachment uploaded by someone else', function (): void {
    [$organization, $visitorToken, $conversationId] = attachmentFixture();

    $visitorAttachment = $this->withToken($visitorToken)
        ->post("/api/widget/conversations/{$conversationId}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('mine.txt', 'visitor file'),
        ])->json('attachment_id');

    // The agent cannot attach the visitor's upload to their own message.
    $this->withToken(agentToken($organization))
        ->postJson("/api/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => 'stealing your attachment',
            'attachment_ids' => [$visitorAttachment],
        ])->assertStatus(422);
});
