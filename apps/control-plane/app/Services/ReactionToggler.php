<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Message;
use App\Models\MessageReaction;

/**
 * Teams-style reaction semantics (Phase 28): one reaction per reactor per
 * message — same emoji toggles off, a different emoji replaces.
 */
final class ReactionToggler
{
    /** @return array<int, array{emoji: string, count: int}> the fresh summary */
    public function toggle(Message $message, string $reactorType, string $reactorId, string $emoji): array
    {
        $existing = MessageReaction::query()
            ->where('message_id', $message->id)
            ->where('reactor_type', $reactorType)
            ->where('reactor_id', $reactorId)
            ->first();

        if ($existing !== null && $existing->emoji === $emoji) {
            $existing->delete();
        } elseif ($existing !== null) {
            $existing->update(['emoji' => $emoji]);
        } else {
            MessageReaction::query()->create([
                'message_id' => $message->id,
                'reactor_type' => $reactorType,
                'reactor_id' => $reactorId,
                'emoji' => $emoji,
            ]);
        }

        return $this->summary($message);
    }

    /**
     * Set a reactor's reaction to an ABSOLUTE state (Phase 38). Channels like
     * Telegram report the resulting reaction, not a toggle intent: a non-null
     * emoji becomes the reactor's single reaction; null clears it.
     *
     * @return array<int, array{emoji: string, count: int}> the fresh summary
     */
    public function set(Message $message, string $reactorType, string $reactorId, ?string $emoji): array
    {
        $existing = MessageReaction::query()
            ->where('message_id', $message->id)
            ->where('reactor_type', $reactorType)
            ->where('reactor_id', $reactorId)
            ->first();

        if ($emoji === null || $emoji === '') {
            $existing?->delete();
        } elseif ($existing !== null) {
            if ($existing->emoji !== $emoji) {
                $existing->update(['emoji' => $emoji]);
            }
        } else {
            MessageReaction::query()->create([
                'message_id' => $message->id,
                'reactor_type' => $reactorType,
                'reactor_id' => $reactorId,
                'emoji' => $emoji,
            ]);
        }

        return $this->summary($message);
    }

    /** @return array<int, array{emoji: string, count: int}> reactions grouped by emoji. */
    private function summary(Message $message): array
    {
        return MessageReaction::query()
            ->where('message_id', $message->id)
            ->get()
            ->groupBy('emoji')
            ->map(fn ($group, string $groupEmoji): array => ['emoji' => $groupEmoji, 'count' => $group->count()])
            ->values()
            ->all();
    }
}
