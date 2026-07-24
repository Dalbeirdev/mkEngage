<?php

declare(strict_types=1);

namespace App\Services\Insights;

use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * mkEngage Insights v1 (§ analytics). Aggregates the operational tables into
 * the dashboard's overview metrics over a date range.
 *
 * Storage note: the directive routes HIGH-VOLUME analytics to ClickHouse and
 * permits PostgreSQL for smaller administrative reports. This v1 is the
 * PostgreSQL path — every query runs under the request's tenant context, so
 * RLS scopes all aggregates to the caller's organization automatically (the
 * numbers can never include another tenant's rows). A ClickHouse-backed
 * implementation can replace this class behind the same shape when the
 * analytics pipeline (analytics-consumer) lands.
 */
final class InsightsService
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(Carbon $from, Carbon $to): array
    {
        // Half-open [from, to) on whole days; `to` is inclusive of its day.
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        return [
            'range' => ['from' => $start->toDateString(), 'to' => $to->toDateString()],
            'conversations' => $this->conversations($start, $end),
            'messages' => $this->messages($start, $end),
            'by_department' => $this->byDepartment($start, $end),
            'daily' => $this->daily($start, $end),
        ];
    }

    /** Narrow a DB aggregate (typed mixed) to int; non-numeric ⇒ 0. */
    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** @return array<string, mixed> */
    private function conversations(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('conversations')
            ->where('organization_id', $this->tenant->organizationId())
            ->selectRaw('status, count(*) as n')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('status')
            ->pluck('n', 'status');

        $open = $this->toInt($rows['open'] ?? 0);
        $pending = $this->toInt($rows['pending'] ?? 0);
        $closed = $this->toInt($rows['closed'] ?? 0);
        $total = $open + $pending + $closed;

        return [
            'total' => $total,
            'open' => $open,
            'pending' => $pending,
            'closed' => $closed,
            // Share of conversations that reached a closed (resolved) state.
            'resolution_rate' => $total > 0 ? round($closed / $total, 3) : 0.0,
        ];
    }

    /** @return array<string, mixed> */
    private function messages(Carbon $start, Carbon $end): array
    {
        $bySender = DB::table('messages')
            ->where('organization_id', $this->tenant->organizationId())
            ->selectRaw('sender_type, count(*) as n')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('sender_type')
            ->pluck('n', 'sender_type');

        $senders = [];
        $total = 0;
        foreach ($bySender as $type => $n) {
            if (is_string($type)) {
                $count = $this->toInt($n);
                $senders[$type] = $count;
                $total += $count;
            }
        }

        $bot = $senders['chatbot'] ?? 0;
        $agent = $senders['agent'] ?? 0;
        $handled = $bot + $agent;

        return [
            'total' => $total,
            'by_sender' => $senders,
            // Of the messages a HUMAN or BOT sent, what share came from the bot.
            'automation_rate' => $handled > 0 ? round($bot / $handled, 3) : 0.0,
        ];
    }

    /** @return list<array{department_name: string, conversations: int}> */
    private function byDepartment(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('conversations')
            ->where('conversations.organization_id', $this->tenant->organizationId())
            ->leftJoin('departments', 'conversations.department_id', '=', 'departments.id')
            ->selectRaw("coalesce(departments.name, 'Unassigned') as department_name, count(*) as n")
            ->whereBetween('conversations.created_at', [$start, $end])
            ->groupBy('departments.name')
            ->orderByDesc('n')
            ->get();

        return array_values($rows->map(fn ($r): array => [
            'department_name' => is_string($r->department_name) ? $r->department_name : 'Unassigned',
            'conversations' => $this->toInt($r->n),
        ])->all());
    }

    /**
     * Daily counts for the range (dense — days with zero are filled).
     *
     * @return list<array{date: string, conversations: int, messages: int}>
     */
    private function daily(Carbon $start, Carbon $end): array
    {
        // DATE(created_at) is portable across PostgreSQL and SQLite (the CI
        // app-layer suite), unlike the PG-only `created_at::date` cast.
        $orgId = $this->tenant->organizationId();

        $convByDay = DB::table('conversations')
            ->where('organization_id', $orgId)
            ->selectRaw('DATE(created_at) as d, count(*) as n')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('d')
            ->pluck('n', 'd');

        $msgByDay = DB::table('messages')
            ->where('organization_id', $orgId)
            ->selectRaw('DATE(created_at) as d, count(*) as n')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('d')
            ->pluck('n', 'd');

        $series = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $key = $day->toDateString();
            $series[] = [
                'date' => $key,
                'conversations' => $this->toInt($convByDay[$key] ?? 0),
                'messages' => $this->toInt($msgByDay[$key] ?? 0),
            ];
        }

        return $series;
    }
}
