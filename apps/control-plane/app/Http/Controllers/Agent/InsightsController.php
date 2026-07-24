<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Services\Insights\InsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * mkEngage Insights overview. Tenant-scoped by RLS (the request already runs
 * under the caller's organization context) — aggregates can never include
 * another tenant's data.
 */
final class InsightsController extends Controller
{
    public function overview(Request $request, InsightsService $insights): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : Carbon::now();
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])
            : $to->copy()->subDays(29); // default: trailing 30 days inclusive

        // Guard against inverted / oversized ranges (max 366 days).
        abort_if($from->gt($to), 422, 'from must be on or before to.');
        abort_if($from->diffInDays($to) > 366, 422, 'Range too large (max 366 days).');

        return response()->json($insights->overview($from, $to));
    }
}
