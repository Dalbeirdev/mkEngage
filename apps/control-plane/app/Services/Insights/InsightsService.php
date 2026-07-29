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
            'csat' => $this->csat($start, $end),
            'by_department' => $this->byDepartment($start, $end),
            'daily' => $this->daily($start, $end),
            // Insights v2 (Phase 34)
            'by_channel' => $this->byChannel($start, $end),
            'first_response' => $this->firstResponse($start, $end),
            'hourly' => $this->hourly($start, $end),
            'agents' => $this->agents($start, $end),
        ];
    }

    /**
     * Conversations per channel type; the widget's null channel reports as
     * "web". Raw query ⇒ explicit org filter (two-layer tenancy).
     *
     * @return array<string, mixed>
     */
    private function byChannel(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('conversations')
            ->leftJoin('channels', 'channels.id', '=', 'conversations.channel_id')
            ->where('conversations.organization_id', $this->tenant->organizationId())
            ->whereBetween('conversations.created_at', [$start, $end])
            ->selectRaw("coalesce(channels.type, 'web') as channel_type, count(*) as n")
            ->groupBy('channel_type')
            ->pluck('n', 'channel_type');

        return collect(['web', 'whatsapp', 'telegram', 'messenger', 'instagram'])
            ->mapWithKeys(fn (string $type): array => [$type => $this->toInt($rows[$type] ?? 0)])
            ->all();
    }

    /**
     * First-response time: per conversation in range, seconds between the
     * first inbound (visitor/contact) message and the first HUMAN agent
     * reply after it. Bot replies are reported separately — instant bot
     * answers must not flatter the human number.
     *
     * @return array<string, mixed>
     */
    private function firstResponse(Carbon $start, Carbon $end): array
    {
        $conversationIds = DB::table('conversations')
            ->where('organization_id', $this->tenant->organizationId())
            ->whereBetween('created_at', [$start, $end])
            ->pluck('id');

        $agentSeconds = [];
        $botSeconds = [];

        // Bounded: overview ranges cap at 366 days and per-org volumes are
        // administrative-report scale here (ClickHouse path takes over past
        // that, see the class docblock).
        foreach ($conversationIds->chunk(200) as $chunk) {
            $messages = DB::table('messages')
                ->where('organization_id', $this->tenant->organizationId())
                ->whereIn('conversation_id', $chunk->all())
                ->orderBy('sequence_number')
                ->get(['conversation_id', 'sender_type', 'sent_at']);

            foreach ($messages->groupBy('conversation_id') as $thread) {
                $firstInboundAt = null;
                $agentRecorded = false;
                $botRecorded = false;

                foreach ($thread as $message) {
                    $sentAt = is_string($message->sent_at) ? Carbon::parse($message->sent_at) : null;
                    if ($sentAt === null) {
                        continue;
                    }

                    if (in_array($message->sender_type, ['visitor', 'contact'], true)) {
                        $firstInboundAt ??= $sentAt;
                    } elseif ($firstInboundAt !== null && ! $agentRecorded && $message->sender_type === 'agent') {
                        $agentSeconds[] = max(0, (int) $firstInboundAt->diffInSeconds($sentAt, true));
                        $agentRecorded = true;
                    } elseif ($firstInboundAt !== null && ! $botRecorded && $message->sender_type === 'chatbot') {
                        $botSeconds[] = max(0, (int) $firstInboundAt->diffInSeconds($sentAt, true));
                        $botRecorded = true;
                    }

                    if ($agentRecorded && $botRecorded) {
                        break;
                    }
                }
            }
        }

        $average = fn (array $values): ?int => $values === [] ? null : (int) round(array_sum($values) / count($values));
        $median = function (array $values): ?int {
            if ($values === []) {
                return null;
            }
            sort($values);
            $mid = intdiv(count($values), 2);

            return count($values) % 2 === 1
                ? $values[$mid]
                : (int) round(($values[$mid - 1] + $values[$mid]) / 2);
        };

        return [
            'agent_avg_seconds' => $average($agentSeconds),
            'agent_median_seconds' => $median($agentSeconds),
            'bot_avg_seconds' => $average($botSeconds),
            'answered_by_agent' => count($agentSeconds),
        ];
    }

    /**
     * Message volume by hour of day (0-23) — staffing heatmap.
     *
     * @return list<array{hour: int, messages: int}>
     */
    private function hourly(Carbon $start, Carbon $end): array
    {
        $hourExpression = DB::connection()->getDriverName() === 'pgsql'
            ? "cast(date_part('hour', sent_at) as integer)"
            : "cast(strftime('%H', sent_at) as integer)";

        $rows = DB::table('messages')
            ->where('organization_id', $this->tenant->organizationId())
            ->whereBetween('sent_at', [$start, $end])
            ->selectRaw("{$hourExpression} as hour, count(*) as n")
            ->groupBy('hour')
            ->pluck('n', 'hour');

        return array_values(collect(range(0, 23))
            ->map(fn (int $hour): array => ['hour' => $hour, 'messages' => $this->toInt($rows[$hour] ?? 0)])
            ->all());
    }

    /**
     * Agent leaderboard: replies sent, conversations closed while assigned,
     * and the average CSAT of those closed conversations.
     *
     * @return list<array<string, mixed>>
     */
    private function agents(Carbon $start, Carbon $end): array
    {
        $orgId = $this->tenant->organizationId();

        $replies = DB::table('messages')
            ->where('organization_id', $orgId)
            ->where('sender_type', 'agent')
            ->whereBetween('sent_at', [$start, $end])
            ->selectRaw('sender_id, count(*) as n')
            ->groupBy('sender_id')
            ->pluck('n', 'sender_id');

        $closedRows = DB::table('conversations')
            ->where('organization_id', $orgId)
            ->where('status', 'closed')
            ->whereNotNull('assigned_agent_id')
            ->whereBetween('closed_at', [$start, $end])
            ->selectRaw('assigned_agent_id, count(*) as closed, avg(csat_rating) as avg_csat')
            ->groupBy('assigned_agent_id')
            ->get()
            ->keyBy('assigned_agent_id');

        $agentIds = $replies->keys()->merge($closedRows->keys())->unique()->values();
        if ($agentIds->isEmpty()) {
            return [];
        }

        $names = DB::table('users')
            ->where('organization_id', $orgId)
            ->whereIn('id', $agentIds->all())
            ->pluck('name', 'id');

        return array_values($agentIds
            ->map(function (mixed $agentId) use ($replies, $closedRows, $names): array {
                $closed = $closedRows->get($agentId);
                $avgCsat = is_object($closed) && is_numeric($closed->avg_csat ?? null)
                    ? round((float) $closed->avg_csat, 2)
                    : null;

                return [
                    'agent_id' => is_string($agentId) ? $agentId : (string) $agentId,
                    'name' => is_string($names[$agentId] ?? null) ? $names[$agentId] : 'Unknown',
                    'replies' => $this->toInt($replies[$agentId] ?? 0),
                    'closed' => is_object($closed) ? $this->toInt($closed->closed ?? 0) : 0,
                    'avg_csat' => $avgCsat,
                ];
            })
            ->sortByDesc('replies')
            ->all());
    }

    /**
     * CSAT (Phase 23): average rating + response volume over rated
     * conversations in range. Raw query ⇒ explicit org filter (two-layer
     * tenancy: RLS is the second layer, RULES-tenant-isolation #2).
     *
     * @return array<string, mixed>
     */
    private function csat(Carbon $start, Carbon $end): array
    {
        $row = DB::table('conversations')
            ->where('organization_id', $this->tenant->organizationId())
            ->whereNotNull('csat_rating')
            ->whereBetween('csat_rated_at', [$start, $end])
            ->selectRaw('count(*) as n, avg(csat_rating) as avg_rating')
            ->first();

        $count = $this->toInt($row?->n);
        $average = is_numeric($row?->avg_rating) ? round((float) $row->avg_rating, 2) : null;

        return [
            'responses' => $count,
            'average' => $count > 0 ? $average : null,
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
