<?php

declare(strict_types=1);

use App\Jobs\DeliverChannelMessage;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Services\ConversationMessenger;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 37: outbound media delivery to channels. An agent/bot message with a
 * clean attachment ships the file as native media on Telegram/WhatsApp/
 * Messenger (plus text body when present).
 */

/** @return array{0: Organization, 1: Channel, 2: Conversation} */
function p37Setup(string $type): array
{
    Storage::fake(config()->string('attachments.disk'));
    $org = Organization::factory()->create();

    [$channel, $conversation] = app(Tenancy::class)->run($org->id, function () use ($org, $type): array {
        $config = match ($type) {
            'telegram' => ['bot_token' => '123:TOK'],
            'messenger' => ['page_id' => 'pg', 'access_token' => 't', 'app_secret' => 's'],
            default => ['phone_number_id' => 'pn', 'access_token' => 't', 'app_secret' => 's'],
        };
        $channel = Channel::query()->create([
            'organization_id' => $org->id, 'type' => $type, 'name' => 'C',
            'status' => 'active', 'config' => $config, 'webhook_verify_token' => 'v',
        ]);
        $conversation = Conversation::query()->create([
            'channel_id' => $channel->id, 'external_thread_id' => '900',
        ]);

        return [$channel, $conversation];
    });

    return [$org, $channel, $conversation];
}

/** Send an agent message carrying a clean image attachment. */
function p37SendWithImage(Organization $org, Channel $channel, Conversation $conversation): void
{
    app(Tenancy::class)->run($org->id, function () use ($org, $channel, $conversation): void {
        $path = 'att/'.Str::uuid7().'.png';
        Storage::disk(config()->string('attachments.disk'))->put($path, 'PNGDATA');

        $result = app(ConversationMessenger::class)->send(
            conversation: $conversation, senderType: 'agent',
            senderId: Str::uuid7()->toString(), body: 'diagram.png',
            idempotencyKey: Str::uuid7()->toString(), channelId: $channel->id,
        );

        Attachment::query()->create([
            'organization_id' => $org->id,
            'conversation_id' => $conversation->id,
            'message_id' => $result['message']->id,
            'uploader_type' => 'user', 'uploader_id' => Str::uuid7()->toString(),
            'file_name' => 'diagram.png', 'content_type' => 'image/png',
            'size_bytes' => 7, 'checksum_sha256' => str_repeat('a', 64),
            'storage_path' => $path, 'scan_status' => 'clean',
        ]);
        // Deliver runs the just-created message's attachment.
        DeliverChannelMessage::dispatchSync((string) $org->id, $result['message']->id);
    });
}

it('sends an image to Telegram via sendPhoto and does not double-send the filename as text', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    [$org, $channel, $conversation] = p37Setup('telegram');
    p37SendWithImage($org, $channel, $conversation);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendPhoto')
        && $request->isMultipart());
    // The body was just the filename, so no redundant sendMessage text call.
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/sendMessage'));
});

it('uploads then sends WhatsApp media by id', function (): void {
    Http::fake([
        'graph.facebook.com/*/media' => Http::response(['id' => 'media-123'], 200),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'w']]], 200),
    ]);
    [$org, $channel, $conversation] = p37Setup('whatsapp');
    p37SendWithImage($org, $channel, $conversation);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/media') && $request->isMultipart());
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/messages')
        && ($request['type'] ?? null) === 'image'
        && ($request['image']['id'] ?? null) === 'media-123');
});

it('sends a Messenger attachment via the Send API', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['attachment_id' => 'a1'], 200)]);
    [$org, $channel, $conversation] = p37Setup('messenger');
    p37SendWithImage($org, $channel, $conversation);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/me/messages') && $request->isMultipart());
});

it('sends non-image files as documents on Telegram', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    [$org, $channel, $conversation] = p37Setup('telegram');

    app(Tenancy::class)->run($org->id, function () use ($org, $channel, $conversation): void {
        $path = 'att/'.Str::uuid7().'.pdf';
        Storage::disk(config()->string('attachments.disk'))->put($path, '%PDF-1.4');
        $result = app(ConversationMessenger::class)->send(
            conversation: $conversation, senderType: 'agent', senderId: Str::uuid7()->toString(),
            body: 'specs.pdf', idempotencyKey: Str::uuid7()->toString(), channelId: $channel->id,
        );
        Attachment::query()->create([
            'organization_id' => $org->id, 'conversation_id' => $conversation->id,
            'message_id' => $result['message']->id, 'uploader_type' => 'user',
            'uploader_id' => Str::uuid7()->toString(), 'file_name' => 'specs.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => 8,
            'checksum_sha256' => str_repeat('b', 64), 'storage_path' => $path, 'scan_status' => 'clean',
        ]);
        DeliverChannelMessage::dispatchSync((string) $org->id, $result['message']->id);
    });

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendDocument'));
});

it('never delivers quarantined attachments', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    [$org, $channel, $conversation] = p37Setup('telegram');

    app(Tenancy::class)->run($org->id, function () use ($org, $channel, $conversation): void {
        $path = 'att/'.Str::uuid7().'.png';
        Storage::disk(config()->string('attachments.disk'))->put($path, 'X');
        $result = app(ConversationMessenger::class)->send(
            conversation: $conversation, senderType: 'agent', senderId: Str::uuid7()->toString(),
            body: 'evil.png', idempotencyKey: Str::uuid7()->toString(), channelId: $channel->id,
        );
        Attachment::query()->create([
            'organization_id' => $org->id, 'conversation_id' => $conversation->id,
            'message_id' => $result['message']->id, 'uploader_type' => 'user',
            'uploader_id' => Str::uuid7()->toString(), 'file_name' => 'evil.png',
            'content_type' => 'image/png', 'size_bytes' => 1,
            'checksum_sha256' => str_repeat('c', 64), 'storage_path' => $path,
            'scan_status' => 'quarantined',
        ]);
        DeliverChannelMessage::dispatchSync((string) $org->id, $result['message']->id);
    });

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/sendPhoto')
        || str_contains($request->url(), '/sendDocument'));
});
