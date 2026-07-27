<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Visitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visitor presence heartbeat (Phase 24, §4 consent-aware): keeps the live
 * visitor board fresh. Page URL/title are stored ONLY under granted consent —
 * a denied/unknown visitor still shows as "online" but never where.
 *
 * The response carries the visitor's most recent non-closed conversation id
 * so the widget can ADOPT an agent-initiated conversation (proactive chat):
 * the agent starts the thread from the live board, the next heartbeat hands
 * it to the widget, the widget opens.
 */
final class WidgetHeartbeatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $visitor = $request->user('widget');
        abort_unless($visitor instanceof Visitor, 403);

        $validated = $request->validate([
            'url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $visitor->last_seen_at = now();
        if ($visitor->consent_state === 'granted') {
            $visitor->current_url = $validated['url'] ?? $visitor->current_url;
            $visitor->page_title = $validated['title'] ?? $visitor->page_title;
        }
        $visitor->save();

        $conversation = Conversation::query()
            ->where('visitor_id', $visitor->id)
            ->where('status', '!=', 'closed')
            ->latest('created_at')
            ->first();

        return response()->json([
            'conversation_id' => $conversation?->id,
            'last_sequence' => $conversation->last_sequence ?? 0,
        ]);
    }
}
