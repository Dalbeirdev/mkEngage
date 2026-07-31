<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Services\Insights\InsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        [$from, $to] = $this->range($request);

        return response()->json($insights->overview($from, $to));
    }

    /**
     * The overview as a downloadable CSV (summary, daily series, channels,
     * hourly histogram, agent leaderboard, SLA). Built fully in memory —
     * a streamed body would run after the tenant context is torn down.
     */
    public function export(Request $request, InsightsService $insights): Response
    {
        [$from, $to] = $this->range($request);
        $overview = $insights->overview($from, $to);

        $handle = fopen('php://temp', 'r+');
        abort_if($handle === false, 500, 'Export buffer unavailable.');

        $row = function (array $cells) use ($handle): void {
            fputcsv($handle, $cells, escape: '\\');
        };
        $str = fn (mixed $value): string => is_scalar($value) ? (string) $value : '';
        $section = function (string $title, mixed $rows, array $header, callable $mapper) use ($row): void {
            if (! is_array($rows) || $rows === []) {
                return;
            }
            $row([]);
            $row([$title]);
            $row($header);
            foreach ($rows as $key => $item) {
                $row($mapper($key, $item));
            }
        };

        $range = is_array($overview['range'] ?? null) ? $overview['range'] : [];
        $row(['mkEngage Insights', $str($range['from'] ?? '').' to '.$str($range['to'] ?? '')]);
        $row([]);
        $row(['Metric', 'Value']);

        $summarySources = [
            'Conversations' => ['conversations', 'total'],
            'Open' => ['conversations', 'open'],
            'Pending' => ['conversations', 'pending'],
            'Closed' => ['conversations', 'closed'],
            'Resolution rate' => ['conversations', 'resolution_rate'],
            'Messages' => ['messages', 'total'],
            'Automation rate' => ['messages', 'automation_rate'],
            'CSAT responses' => ['csat', 'responses'],
            'CSAT average' => ['csat', 'average'],
            'Agent avg first response (s)' => ['first_response', 'agent_avg_seconds'],
            'SLA tracked' => ['sla', 'tracked'],
            'SLA breached' => ['sla', 'breached'],
            'SLA breach rate' => ['sla', 'breach_rate'],
        ];
        foreach ($summarySources as $label => [$group, $key]) {
            $values = is_array($overview[$group] ?? null) ? $overview[$group] : [];
            $row([$label, $str($values[$key] ?? '')]);
        }

        $section('Daily', $overview['daily'] ?? null, ['Date', 'Conversations', 'Messages'],
            fn ($key, $day): array => is_array($day)
                ? [$str($day['date'] ?? ''), $str($day['conversations'] ?? ''), $str($day['messages'] ?? '')]
                : []);

        $section('Channels', $overview['by_channel'] ?? null, ['Channel', 'Conversations'],
            fn ($key, $count): array => [$str($key), $str($count)]);

        $section('Hourly', $overview['hourly'] ?? null, ['Hour', 'Messages'],
            fn ($key, $hour): array => is_array($hour)
                ? [$str($hour['hour'] ?? ''), $str($hour['messages'] ?? '')]
                : []);

        $section('Agents', $overview['agents'] ?? null, ['Agent', 'Replies', 'Closed', 'Avg CSAT'],
            fn ($key, $agent): array => is_array($agent)
                ? [$str($agent['name'] ?? ''), $str($agent['replies'] ?? ''), $str($agent['closed'] ?? ''), $str($agent['avg_csat'] ?? '')]
                : []);

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $filename = sprintf('mkengage-insights-%s-to-%s.csv', $str($range['from'] ?? 'start'), $str($range['to'] ?? 'end'));

        return response(is_string($csv) ? $csv : '', 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
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

        return [$from, $to];
    }
}
