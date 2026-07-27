<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Services\LeadScorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Live visitor board (Phase 24): visitors whose heartbeat landed within the
 * liveness window. Tenant scope (Eloquent + RLS) bounds the query; page
 * context only exists for consent-granted visitors (heartbeat never stores
 * it otherwise).
 */
final class LiveVisitorController extends Controller
{
    /** Seconds since the last heartbeat before a visitor drops off the board. */
    private const LIVENESS_WINDOW_SECONDS = 60;

    public function index(LeadScorer $scorer): JsonResponse
    {
        $visitors = Visitor::query()
            ->with('contact:id,name,email')
            ->where('last_seen_at', '>=', now()->subSeconds(self::LIVENESS_WINDOW_SECONDS))
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        // One query for the open conversation per live visitor (no N+1).
        $openConversations = Conversation::query()
            ->whereIn('visitor_id', $visitors->pluck('id')->all())
            ->where('status', '!=', 'closed')
            ->orderBy('created_at')
            ->get()
            ->keyBy('visitor_id');

        // One aggregate for message counts (lead-scoring signal, Phase 25).
        // Raw query ⇒ explicit org filter (two-layer tenancy).
        $messageCounts = DB::table('messages')
            ->whereIn('sender_id', $visitors->pluck('id')->all())
            ->where('sender_type', 'visitor')
            ->where('organization_id', $visitors->first()->organization_id ?? '')
            ->selectRaw('sender_id, count(*) as n')
            ->groupBy('sender_id')
            ->pluck('n', 'sender_id');

        return response()->json([
            'data' => $visitors->map(function (Visitor $visitor) use ($openConversations, $messageCounts, $scorer): array {
                $conversation = $openConversations->get($visitor->id);
                $sent = $messageCounts->get($visitor->id);
                $score = $scorer->score(
                    $visitor,
                    $conversation !== null,
                    is_numeric($sent) ? (int) $sent : 0,
                );

                return [
                    'lead_score' => $score,
                    'lead_bucket' => $scorer->bucket($score),
                    'visitor_id' => $visitor->id,
                    'display_name' => $visitor->display_name,
                    'contact_name' => $visitor->contact?->name,
                    'contact_email' => $visitor->contact?->email,
                    'consent_state' => $visitor->consent_state,
                    'current_url' => $visitor->current_url,
                    'page_title' => $visitor->page_title,
                    'first_seen_at' => $visitor->created_at?->toIso8601String(),
                    'last_seen_at' => $visitor->last_seen_at?->toIso8601String(),
                    'conversation_id' => $conversation?->id,
                ];
            })->values()->all(),
        ]);
    }
}
