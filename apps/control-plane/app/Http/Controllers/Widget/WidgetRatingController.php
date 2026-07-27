<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Visitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CSAT rating (Phase 23): the visitor rates a CLOSED conversation 1–5 with an
 * optional comment. Ownership is enforced (404 on foreign/unknown ids — no
 * existence leak) on top of the tenant scope. Re-rating overwrites: the
 * visitor's latest opinion wins.
 */
final class WidgetRatingController extends Controller
{
    public function __invoke(Request $request, string $conversationId): JsonResponse
    {
        $visitor = $request->user('widget');
        abort_unless($visitor instanceof Visitor, 403);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $conversation = Conversation::query()
            ->whereKey($conversationId)
            ->where('visitor_id', $visitor->id)
            ->first();

        abort_if($conversation === null, 404);
        abort_unless($conversation->status === 'closed', 409, 'Only closed conversations can be rated.');

        $conversation->csat_rating = $validated['rating'];
        $conversation->csat_comment = $validated['comment'] ?? null;
        $conversation->csat_rated_at = now();
        $conversation->save();

        return response()->json([
            'conversation_id' => $conversation->id,
            'csat_rating' => $conversation->csat_rating,
        ], 201);
    }
}
