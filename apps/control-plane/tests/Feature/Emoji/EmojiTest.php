<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Emoji integrity (§12): messages must survive the full accept→persist→read
 * pipeline byte-for-byte, including multi-codepoint sequences (ZWJ families,
 * regional-indicator flags, skin-tone modifiers) — no mojibake, no
 * double-encoding, no HTML-entity/escape leakage.
 */
function emojiVisitor(): array
{
    Http::fake();
    $org = Organization::factory()->create();
    $token = test()->postJson('/api/widget/session', ['site_key' => $org->widget_site_key])
        ->assertCreated()->json('token');
    $conversationId = test()->withToken($token)
        ->postJson('/api/widget/conversations', [])->assertCreated()->json('conversation_id');

    return [$token, $conversationId];
}

dataset('emojis', [
    'simple' => ['Hello 👋 Thank you 😊 🎉'],
    'zwj-family' => ['Family 👨‍👩‍👧‍👦'],
    'flag' => ['Flag 🇺🇸'],
    'skin-tone' => ['Thumbs 👍🏽'],
    'symbols' => ['Urgent ⚠️ Support 🛠️ Done ✅'],
]);

it('round-trips emoji byte-for-byte through send and re-read', function (string $text): void {
    [$token, $conversationId] = emojiVisitor();

    $sent = test()->withToken($token)
        ->postJson("/api/widget/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => $text,
        ])->assertCreated();

    // Send response echoes the stored value exactly.
    expect($sent->json('body'))->toBe($text);

    // Re-reading the history returns the same bytes (not re-encoded on the way out).
    $list = test()->withToken($token)
        ->getJson("/api/widget/conversations/{$conversationId}/messages")->assertOk();
    expect($list->json('data.0.body'))->toBe($text)
        // No escape/entity leakage.
        ->and($list->json('data.0.body'))->not->toContain('\\u')
        ->and($list->json('data.0.body'))->not->toContain('&#');
})->with('emojis');

it('preserves the exact codepoint length of a ZWJ family sequence', function (): void {
    [$token, $conversationId] = emojiVisitor();
    $family = '👨‍👩‍👧‍👦'; // 7 codepoints (4 people + 3 ZWJ)

    test()->withToken($token)
        ->postJson("/api/widget/conversations/{$conversationId}/messages", [
            'idempotency_key' => (string) Str::uuid7(),
            'content_type' => 'text',
            'body' => $family,
        ])->assertCreated();

    $stored = test()->withToken($token)
        ->getJson("/api/widget/conversations/{$conversationId}/messages")
        ->json('data.0.body');

    // If the ZWJ joiners were stripped, this would render as 4 separate people
    // and the codepoint count would drop from 7.
    expect(mb_strlen($stored))->toBe(7)
        ->and($stored)->toBe($family);
});
