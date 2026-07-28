"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { insightsOverviewSchema } from "@/lib/api/schemas";

async function fetchOverview() {
  const res = await fetch("/api/cp/insights/overview", { cache: "no-store" });
  if (!res.ok) throw new Error(`Insights failed (${res.status})`);
  return insightsOverviewSchema.parse(await res.json());
}

function pct(n: number): string {
  return `${Math.round(n * 100)}%`;
}

/** mkEngage Insights overview — trailing 30-day metrics, tenant-scoped. */
export function Insights() {
  const t = useTranslations("insights");
  const { data, isPending, isError } = useQuery({
    queryKey: ["insights", "overview"],
    queryFn: fetchOverview,
    refetchInterval: 30000,
  });

  if (isPending) {
    return (
      <p className="text-sm text-zinc-500" role="status">
        {t("loading")}
      </p>
    );
  }
  if (isError || data === undefined) {
    return (
      <p className="text-sm text-red-600" role="alert">
        {t("error")}
      </p>
    );
  }

  const cards = [
    { label: t("conversations"), value: data.conversations.total },
    { label: t("resolved"), value: `${data.conversations.closed} (${pct(data.conversations.resolution_rate)})` },
    { label: t("messages"), value: data.messages.total },
    { label: t("automation"), value: pct(data.messages.automation_rate) },
    {
      label: t("csat"),
      value:
        data.csat.average !== null
          ? `★ ${data.csat.average.toFixed(1)} (${data.csat.responses})`
          : t("csatNone"),
    },
    {
      label: t("firstResponse"),
      value:
        data.first_response.agent_avg_seconds !== null
          ? formatSeconds(data.first_response.agent_avg_seconds)
          : t("frtNone"),
    },
  ];

  const maxDaily = Math.max(1, ...data.daily.map((d) => d.conversations));
  const maxDept = Math.max(1, ...data.by_department.map((d) => d.conversations));
  const channels = Object.entries(data.by_channel);
  const maxChannel = Math.max(1, ...channels.map(([, n]) => n));
  const maxHour = Math.max(1, ...data.hourly.map((h) => h.messages));

  return (
    <div className="space-y-6">
      <p className="text-sm text-zinc-500">{t("range", { from: data.range.from, to: data.range.to })}</p>

      {/* Metric cards */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
        {cards.map((c) => (
          <div
            key={c.label}
            className="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
          >
            <div className="text-xs font-medium tracking-wide text-zinc-500 uppercase">{c.label}</div>
            <div className="mt-2 text-3xl font-bold tracking-tight tabular-nums">{c.value}</div>
          </div>
        ))}
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Conversations per day */}
        <section className="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
          <h2 className="mb-3 text-sm font-semibold">{t("perDay")}</h2>
          <div className="flex h-32 items-end gap-0.5" role="img" aria-label={t("perDay")}>
            {data.daily.map((d) => (
              <div key={d.date} className="flex-1" title={`${d.date}: ${d.conversations}`}>
                <div
                  className="w-full rounded-t bg-indigo-500/80"
                  style={{ height: `${(d.conversations / maxDaily) * 100}%` }}
                />
              </div>
            ))}
          </div>
          <div className="mt-1 flex justify-between text-[10px] text-zinc-400">
            <span>{data.daily[0]?.date}</span>
            <span>{data.daily[data.daily.length - 1]?.date}</span>
          </div>
        </section>

        {/* By department */}
        <section className="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
          <h2 className="mb-3 text-sm font-semibold">{t("byDepartment")}</h2>
          {data.by_department.length === 0 ? (
            <p className="text-sm text-zinc-500">{t("empty")}</p>
          ) : (
            <ul className="space-y-2">
              {data.by_department.map((d) => (
                <li key={d.department_name} className="text-sm">
                  <div className="mb-0.5 flex justify-between">
                    <span className="truncate">{d.department_name}</span>
                    <span className="tabular-nums text-zinc-500">{d.conversations}</span>
                  </div>
                  <div className="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <div
                      className="h-2 rounded-full bg-indigo-500/80"
                      style={{ width: `${(d.conversations / maxDept) * 100}%` }}
                    />
                  </div>
                </li>
              ))}
            </ul>
          )}
        </section>

        {/* By channel (Phase 34) */}
        <section className="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
          <h2 className="mb-3 text-sm font-semibold">{t("byChannel")}</h2>
          <ul className="space-y-2">
            {channels.map(([channel, count]) => (
              <li key={channel} className="text-sm">
                <div className="mb-0.5 flex justify-between">
                  <span className="capitalize">{channel === "web" ? t("channelWeb") : channel}</span>
                  <span className="tabular-nums text-zinc-500">{count}</span>
                </div>
                <div className="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
                  <div
                    className="h-2 rounded-full bg-emerald-500/80"
                    style={{ width: `${(count / maxChannel) * 100}%` }}
                  />
                </div>
              </li>
            ))}
          </ul>
        </section>

        {/* Busiest hours (Phase 34) */}
        <section className="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
          <h2 className="mb-3 text-sm font-semibold">{t("busiestHours")}</h2>
          <div className="flex h-24 items-end gap-0.5" role="img" aria-label={t("busiestHours")}>
            {data.hourly.map((h) => (
              <div key={h.hour} className="flex-1" title={`${h.hour}:00 — ${h.messages}`}>
                <div
                  className="w-full rounded-t bg-amber-500/80"
                  style={{ height: `${(h.messages / maxHour) * 100}%` }}
                />
              </div>
            ))}
          </div>
          <div className="mt-1 flex justify-between text-[10px] text-zinc-400">
            <span>00:00</span>
            <span>12:00</span>
            <span>23:00</span>
          </div>
        </section>

        {/* Agent leaderboard (Phase 34) */}
        <section className="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm lg:col-span-2 dark:border-zinc-800 dark:bg-zinc-900">
          <h2 className="mb-3 text-sm font-semibold">{t("agentLeaderboard")}</h2>
          {data.agents.length === 0 ? (
            <p className="text-sm text-zinc-500">{t("empty")}</p>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr className="text-start text-xs text-zinc-500">
                  <th className="pb-2 text-start font-medium">{t("colAgent")}</th>
                  <th className="pb-2 text-end font-medium">{t("colReplies")}</th>
                  <th className="pb-2 text-end font-medium">{t("colClosed")}</th>
                  <th className="pb-2 text-end font-medium">{t("colCsat")}</th>
                </tr>
              </thead>
              <tbody>
                {data.agents.map((agent) => (
                  <tr key={agent.agent_id} className="border-t border-zinc-100 dark:border-zinc-800">
                    <td className="py-2">{agent.name}</td>
                    <td className="py-2 text-end tabular-nums">{agent.replies}</td>
                    <td className="py-2 text-end tabular-nums">{agent.closed}</td>
                    <td className="py-2 text-end tabular-nums">
                      {agent.avg_csat !== null ? `★ ${agent.avg_csat.toFixed(1)}` : "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>
      </div>
    </div>
  );
}

/** "95s" → "1m 35s"; hours beyond that. */
function formatSeconds(seconds: number): string {
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
  return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
}
