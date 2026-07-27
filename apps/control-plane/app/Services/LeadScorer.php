<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Visitor;
use Carbon\CarbonImmutable;

/**
 * Rule-based lead scoring (Phase 25). Deliberately explainable — every point
 * traces to a visible signal, no opaque model. Scores are computed on read
 * (cheap arithmetic over already-loaded rows), never stored, so they can't
 * go stale.
 *
 *   identified contact (email)   +35
 *   named via pre-chat/identity  +10
 *   has a conversation           +25
 *   visitor wrote messages       +15
 *   time on site ≥ 5 minutes     +10
 *   page context shared (consent) +5
 *                          max = 100
 */
final class LeadScorer
{
    /**
     * @param  bool  $hasConversation  whether an open/any conversation exists
     * @param  int  $visitorMessages  messages the visitor has sent
     */
    public function score(Visitor $visitor, bool $hasConversation, int $visitorMessages = 0): int
    {
        $score = 0;

        if ($visitor->contact_id !== null) {
            $score += 35;
        }

        if ($visitor->display_name !== null && $visitor->display_name !== '') {
            $score += 10;
        }

        if ($hasConversation) {
            $score += 25;
        }

        if ($visitorMessages > 0) {
            $score += 15;
        }

        $firstSeen = $visitor->created_at;
        $lastSeen = $visitor->last_seen_at;
        if ($firstSeen !== null && $lastSeen !== null
            && CarbonImmutable::make($firstSeen)?->diffInSeconds(CarbonImmutable::make($lastSeen), true) >= 300) {
            $score += 10;
        }

        if ($visitor->current_url !== null) {
            $score += 5;
        }

        return min(100, $score);
    }

    /** Bucket for UI badges: hot ≥ 60, warm ≥ 30, else cold. */
    public function bucket(int $score): string
    {
        return match (true) {
            $score >= 60 => 'hot',
            $score >= 30 => 'warm',
            default => 'cold',
        };
    }
}
