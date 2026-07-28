"use client";

import Link from "next/link";
import { useId } from "react";
import { useQuery } from "@tanstack/react-query";

import {
  IconArrowRight,
  IconBot,
  IconChatbots,
  IconClock,
  IconConversations,
  IconMessages,
  IconSparkles,
  IconStar,
  IconTrendDown,
  IconTrendUp,
} from "@/components/icons";
import { conversationListSchema, insightsOverviewSchema, type Conversation } from "@/lib/api/schemas";

async function fetchOverview() {
  const res = await fetch("/api/cp/insights/overview", { cache: "no-store" });
  if (!res.ok) throw new Error(`Insights failed (${res.status})`);
  return insightsOverviewSchema.parse(await res.json());
}

async function fetchRecent() {
  const res = await fetch("/api/cp/conversations", { cache: "no-store" });
  if (!res.ok) throw new Error(`Conversations failed (${res.status})`);
  return conversationListSchema.parse(await res.json()).data;
}

function pct(n: number): string {
  return `${Math.round(n * 100)}%`;
}

function formatSeconds(seconds: number): string {
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
  return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
}

/** Half-over-half change within the range — an honest in-range trend. */
function trend(series: number[]): number | null {
  if (series.length < 4) return null;
  const mid = Math.floor(series.length / 2);
  const a = series.slice(0, mid).reduce((s, v) => s + v, 0);
  const b = series.slice(mid).reduce((s, v) => s + v, 0);
  if (a === 0) return b > 0 ? 1 : null;
  return (b - a) / a;
}

function relativeTime(iso: string | null): string {
  if (iso === null) return "";
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return "";
  const diff = Math.max(0, Date.now() - then);
  const m = Math.floor(diff / 60000);
  if (m < 1) return "Now";
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h ago`;
  return `${Math.floor(h / 24)}d ago`;
}

const CHANNEL_META: Record<string, { label: string; dot: string }> = {
  web: { label: "Website Widget", dot: "bg-indigo-500" },
  whatsapp: { label: "WhatsApp", dot: "bg-emerald-500" },
  telegram: { label: "Telegram", dot: "bg-sky-500" },
  messenger: { label: "Messenger", dot: "bg-blue-500" },
};

function channelMeta(key: string) {
  return CHANNEL_META[key] ?? { label: key.charAt(0).toUpperCase() + key.slice(1), dot: "bg-zinc-400" };
}

const cardShell =
  "rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900";

export function DashboardView() {
  const { data, isPending, isError } = useQuery({
    queryKey: ["insights", "overview"],
    queryFn: fetchOverview,
    refetchInterval: 30000,
  });

  if (isPending) {
    return <p className="text-sm text-zinc-500" role="status">Loading your dashboard…</p>;
  }
  if (isError || data === undefined) {
    return <p className="text-sm text-red-600" role="alert">Couldn’t load insights. Try refreshing.</p>;
  }

  const convSeries = data.daily.map((d) => d.conversations);
  const msgSeries = data.daily.map((d) => d.messages);

  return (
    <div className="space-y-6">
      {/* Metric cards */}
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <MetricCard
          icon={<IconConversations />}
          tint="indigo"
          label="Conversations"
          value={data.conversations.total.toLocaleString()}
          spark={convSeries}
          delta={trend(convSeries)}
        />
        <MetricCard
          icon={<IconMessages />}
          tint="pink"
          label="Messages"
          value={data.messages.total.toLocaleString()}
          spark={msgSeries}
          delta={trend(msgSeries)}
        />
        <MetricCard
          icon={<IconBot />}
          tint="violet"
          label="Bot Handled"
          value={pct(data.messages.automation_rate)}
          caption="of messages by bot"
        />
        <MetricCard
          icon={<IconClock />}
          tint="sky"
          label="Avg First Response"
          value={
            data.first_response.agent_avg_seconds !== null
              ? formatSeconds(data.first_response.agent_avg_seconds)
              : "—"
          }
          caption="agent average"
        />
        <MetricCard
          icon={<IconStar />}
          tint="amber"
          label="CSAT Score"
          value={data.csat.average !== null ? data.csat.average.toFixed(1) : "—"}
          stars={data.csat.average}
          caption={`Based on ${data.csat.responses} rating${data.csat.responses === 1 ? "" : "s"}`}
        />
      </div>

      {/* Charts row */}
      <div className="grid gap-6 lg:grid-cols-4">
        <section className={`${cardShell} lg:col-span-2`}>
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-sm font-semibold">Conversations Over Time</h2>
            <span className="rounded-lg border border-zinc-200 px-2 py-0.5 text-xs text-zinc-500 dark:border-zinc-700">
              Daily
            </span>
          </div>
          <AreaChart daily={data.daily} />
        </section>

        <section className={cardShell}>
          <h2 className="mb-4 text-sm font-semibold">By Department</h2>
          <Donut total={data.conversations.total} segments={data.by_department} />
        </section>

        <section className={cardShell}>
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-sm font-semibold">Live Activity</h2>
          </div>
          <LiveActivity />
        </section>
      </div>

      {/* Bottom row */}
      <div className="grid gap-6 lg:grid-cols-3">
        <section className={cardShell}>
          <h2 className="mb-4 text-sm font-semibold">Conversations by Channel</h2>
          <ChannelBars byChannel={data.by_channel} />
        </section>

        <section className={cardShell}>
          <h2 className="mb-4 text-sm font-semibold">Busiest Hours</h2>
          <HourlyHeatmap hourly={data.hourly} />
        </section>

        <AiSummary
          automationRate={data.messages.automation_rate}
          answeredByAgent={data.first_response.answered_by_agent}
          botAvg={data.first_response.bot_avg_seconds}
        />
      </div>
    </div>
  );
}

const TINTS: Record<string, string> = {
  indigo: "bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300",
  pink: "bg-pink-50 text-pink-600 dark:bg-pink-500/15 dark:text-pink-300",
  violet: "bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300",
  sky: "bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300",
  amber: "bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300",
};

const SPARK_STROKE: Record<string, string> = {
  indigo: "#6366f1",
  pink: "#ec4899",
};

function MetricCard({
  icon,
  tint,
  label,
  value,
  spark,
  delta,
  caption,
  stars,
}: {
  icon: React.ReactNode;
  tint: string;
  label: string;
  value: string;
  spark?: number[];
  delta?: number | null;
  caption?: string;
  stars?: number | null;
}) {
  return (
    <div className={cardShell}>
      <div className="flex items-center gap-2.5">
        <span className={`grid size-9 shrink-0 place-items-center rounded-xl ${TINTS[tint]}`} aria-hidden>
          {icon}
        </span>
        <span className="text-sm font-medium text-zinc-500">{label}</span>
      </div>
      <div className="mt-3 text-3xl font-bold tracking-tight tabular-nums">{value}</div>

      {stars !== undefined && <Stars value={stars} />}

      {delta !== undefined && delta !== null && (
        <div className="mt-2 flex items-center gap-1 text-xs">
          <span className={delta >= 0 ? "text-emerald-600" : "text-red-600"}>
            {delta >= 0 ? <IconTrendUp /> : <IconTrendDown />}
          </span>
          <span className={delta >= 0 ? "font-medium text-emerald-600" : "font-medium text-red-600"}>
            {delta >= 0 ? "+" : ""}
            {Math.round(delta * 100)}%
          </span>
          <span className="text-zinc-400">vs first half</span>
        </div>
      )}

      {spark !== undefined && spark.length > 1 && (
        <Sparkline data={spark} stroke={SPARK_STROKE[tint] ?? "#6366f1"} />
      )}

      {caption !== undefined && delta === undefined && (
        <p className="mt-2 text-xs text-zinc-400">{caption}</p>
      )}
    </div>
  );
}

function Stars({ value }: { value: number | null | undefined }) {
  const filled = value === null || value === undefined ? 0 : Math.round(value);
  return (
    <div className="mt-2 flex gap-0.5 text-indigo-500" aria-hidden>
      {[1, 2, 3, 4, 5].map((i) => (
        <span key={i} className={i <= filled ? "text-indigo-500" : "text-zinc-300 dark:text-zinc-700"}>
          <IconStar />
        </span>
      ))}
    </div>
  );
}

function Sparkline({ data, stroke }: { data: number[]; stroke: string }) {
  const gid = `sp${useId().replace(/:/g, "")}`;
  const w = 120;
  const h = 34;
  const max = Math.max(1, ...data);
  const step = data.length > 1 ? w / (data.length - 1) : w;
  const pts = data.map((v, i) => [i * step, h - (v / max) * (h - 4) - 2] as const);
  const line = pts.map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`).join(" ");
  const area = `M0,${h} L${line.split(" ").join(" L")} L${w},${h} Z`;
  return (
    <svg viewBox={`0 0 ${w} ${h}`} className="mt-3 h-8 w-full" preserveAspectRatio="none" aria-hidden>
      <defs>
        <linearGradient id={gid} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor={stroke} stopOpacity="0.25" />
          <stop offset="1" stopColor={stroke} stopOpacity="0" />
        </linearGradient>
      </defs>
      <path d={area} fill={`url(#${gid})`} />
      <polyline points={line} fill="none" stroke={stroke} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function AreaChart({ daily }: { daily: { date: string; conversations: number }[] }) {
  const gid = `ar${useId().replace(/:/g, "")}`;
  const w = 520;
  const h = 180;
  const values = daily.map((d) => d.conversations);
  const max = Math.max(1, ...values);
  const step = daily.length > 1 ? w / (daily.length - 1) : w;
  const pts = values.map((v, i) => [i * step, h - (v / max) * (h - 16) - 8] as const);
  const line = pts.map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`).join(" ");
  const area = `M0,${h} L${line.split(" ").join(" L")} L${w},${h} Z`;
  const first = daily[0]?.date ?? "";
  const last = daily[daily.length - 1]?.date ?? "";

  return (
    <div>
      <svg viewBox={`0 0 ${w} ${h}`} className="h-44 w-full" preserveAspectRatio="none" role="img" aria-label="Conversations over time">
        <defs>
          <linearGradient id={gid} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stopColor="#6366f1" stopOpacity="0.28" />
            <stop offset="1" stopColor="#6366f1" stopOpacity="0" />
          </linearGradient>
        </defs>
        {[0.25, 0.5, 0.75].map((g) => (
          <line key={g} x1="0" y1={h * g} x2={w} y2={h * g} stroke="currentColor" strokeWidth="0.5" className="text-zinc-200 dark:text-zinc-800" />
        ))}
        <path d={area} fill={`url(#${gid})`} />
        <polyline points={line} fill="none" stroke="#6366f1" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
      <div className="mt-2 flex justify-between text-[11px] text-zinc-400">
        <span>{first}</span>
        <span>{last}</span>
      </div>
    </div>
  );
}

const DONUT_COLORS = ["#6366f1", "#ec4899", "#0ea5e9", "#a78bfa", "#f59e0b", "#10b981"];

function Donut({ total, segments }: { total: number; segments: { department_name: string; conversations: number }[] }) {
  const sum = segments.reduce((s, d) => s + d.conversations, 0);
  const r = 52;
  const c = 2 * Math.PI * r;
  let offset = 0;

  return (
    <div className="flex items-center gap-4">
      <svg viewBox="0 0 140 140" className="size-32 shrink-0">
        <g transform="rotate(-90 70 70)">
          <circle cx="70" cy="70" r={r} fill="none" strokeWidth="16" className="stroke-zinc-100 dark:stroke-zinc-800" />
          {sum > 0 &&
            segments.map((d, i) => {
              const frac = d.conversations / sum;
              const dash = frac * c;
              const el = (
                <circle
                  key={d.department_name}
                  cx="70"
                  cy="70"
                  r={r}
                  fill="none"
                  strokeWidth="16"
                  stroke={DONUT_COLORS[i % DONUT_COLORS.length]}
                  strokeDasharray={`${dash} ${c - dash}`}
                  strokeDashoffset={-offset}
                />
              );
              offset += dash;
              return el;
            })}
        </g>
        <text x="70" y="67" textAnchor="middle" className="fill-zinc-900 text-lg font-bold dark:fill-zinc-100">
          {total}
        </text>
        <text x="70" y="83" textAnchor="middle" className="fill-zinc-400 text-[9px]">
          Total
        </text>
      </svg>
      <ul className="min-w-0 flex-1 space-y-1.5 text-sm">
        {segments.length === 0 && <li className="text-zinc-500">No data yet</li>}
        {segments.map((d, i) => (
          <li key={d.department_name} className="flex items-center gap-2">
            <span className="size-2.5 shrink-0 rounded-full" style={{ background: DONUT_COLORS[i % DONUT_COLORS.length] }} aria-hidden />
            <span className="truncate text-zinc-600 dark:text-zinc-300">{d.department_name}</span>
            <span className="ms-auto tabular-nums text-zinc-500">
              {d.conversations}
              <span className="ms-1 text-zinc-400">({sum > 0 ? Math.round((d.conversations / sum) * 100) : 0}%)</span>
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function ChannelBars({ byChannel }: { byChannel: Record<string, number> }) {
  const entries = Object.entries(byChannel);
  const sum = Math.max(1, entries.reduce((s, [, n]) => s + n, 0));
  const order = ["web", "whatsapp", "telegram", "messenger"];
  entries.sort((a, b) => order.indexOf(a[0]) - order.indexOf(b[0]));

  if (entries.length === 0) return <p className="text-sm text-zinc-500">No channel activity yet.</p>;

  return (
    <ul className="space-y-3">
      {entries.map(([key, n]) => {
        const meta = channelMeta(key);
        return (
          <li key={key} className="text-sm">
            <div className="mb-1 flex items-center gap-2">
              <span className={`size-2.5 rounded-full ${meta.dot}`} aria-hidden />
              <span className="text-zinc-600 dark:text-zinc-300">{meta.label}</span>
              <span className="ms-auto tabular-nums text-zinc-500">
                {n} <span className="text-zinc-400">({Math.round((n / sum) * 100)}%)</span>
              </span>
            </div>
            <div className="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
              <div className={`h-2 rounded-full ${meta.dot}`} style={{ width: `${(n / sum) * 100}%` }} />
            </div>
          </li>
        );
      })}
    </ul>
  );
}

function HourlyHeatmap({ hourly }: { hourly: { hour: number; messages: number }[] }) {
  const max = Math.max(1, ...hourly.map((h) => h.messages));
  return (
    <div>
      <div className="grid grid-cols-12 gap-1">
        {hourly.map((h) => {
          const intensity = h.messages / max;
          return (
            <div
              key={h.hour}
              title={`${h.hour}:00 — ${h.messages} messages`}
              className="aspect-square rounded"
              style={{ backgroundColor: `rgba(99,102,241,${0.12 + intensity * 0.88})` }}
            />
          );
        })}
      </div>
      <div className="mt-3 flex items-center gap-2 text-[11px] text-zinc-400">
        <span>Low</span>
        <div className="h-2 flex-1 rounded-full bg-gradient-to-r from-indigo-100 to-indigo-600" />
        <span>High</span>
      </div>
    </div>
  );
}

function LiveActivity() {
  const { data, isPending } = useQuery({
    queryKey: ["dashboard", "recent"],
    queryFn: fetchRecent,
    refetchInterval: 20000,
  });

  if (isPending) return <p className="text-sm text-zinc-500">Loading…</p>;

  const recent = (data ?? [])
    .slice()
    .sort((a, b) => (b.updated_at ?? "").localeCompare(a.updated_at ?? ""))
    .slice(0, 5);

  if (recent.length === 0) return <p className="text-sm text-zinc-500">No recent conversations.</p>;

  const name = (c: Conversation) => c.contact_name ?? c.visitor_name ?? "Visitor";

  return (
    <ul className="space-y-3">
      {recent.map((c) => {
        const meta = channelMeta(c.channel_type ?? "web");
        return (
          <li key={c.conversation_id} className="flex items-center gap-3">
            <span className="grid size-8 shrink-0 place-items-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
              {name(c).charAt(0).toUpperCase()}
            </span>
            <div className="min-w-0 flex-1">
              <p className="flex items-center gap-1.5 truncate text-sm font-medium">
                <span className={`size-2 shrink-0 rounded-full ${meta.dot}`} aria-hidden />
                {name(c)}
              </p>
              <p className="truncate text-xs text-zinc-400">{meta.label}</p>
            </div>
            <span className="shrink-0 text-xs text-zinc-400">{relativeTime(c.updated_at)}</span>
          </li>
        );
      })}
      <li>
        <Link href="/conversations" className="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">
          View all conversations
          <IconArrowRight />
        </Link>
      </li>
    </ul>
  );
}

function AiSummary({
  automationRate,
  answeredByAgent,
  botAvg,
}: {
  automationRate: number;
  answeredByAgent: number;
  botAvg: number | null;
}) {
  const rows = [
    { icon: <IconBot />, value: pct(automationRate), label: "Conversations handled by bot" },
    { icon: <IconClock />, value: botAvg !== null ? formatSeconds(botAvg) : "—", label: "Avg bot first response" },
    { icon: <IconChatbots />, value: answeredByAgent.toLocaleString(), label: "Answered by an agent" },
  ];
  return (
    <section className="rounded-2xl border border-indigo-200 bg-gradient-to-br from-indigo-50 to-fuchsia-50 p-5 shadow-sm dark:border-indigo-500/20 dark:from-indigo-500/10 dark:to-fuchsia-500/10">
      <div className="mb-3 flex items-center gap-2">
        <span className="grid size-8 place-items-center rounded-xl bg-indigo-600 text-white" aria-hidden>
          <IconSparkles />
        </span>
        <h2 className="text-sm font-semibold">AI Summary</h2>
      </div>
      <p className="mb-4 text-sm text-zinc-600 dark:text-zinc-300">
        Your AI assistant handled <strong className="font-semibold">{pct(automationRate)}</strong> of message
        volume this period.
      </p>
      <ul className="space-y-2.5">
        {rows.map((r) => (
          <li key={r.label} className="flex items-center gap-3">
            <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-white/70 text-indigo-600 dark:bg-white/10 dark:text-indigo-300" aria-hidden>
              {r.icon}
            </span>
            <div className="min-w-0">
              <div className="text-sm font-semibold tabular-nums">{r.value}</div>
              <div className="truncate text-xs text-zinc-500">{r.label}</div>
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}
