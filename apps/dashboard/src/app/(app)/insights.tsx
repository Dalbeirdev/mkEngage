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
  ];

  const maxDaily = Math.max(1, ...data.daily.map((d) => d.conversations));
  const maxDept = Math.max(1, ...data.by_department.map((d) => d.conversations));

  return (
    <div className="space-y-6">
      <p className="text-sm text-zinc-500">{t("range", { from: data.range.from, to: data.range.to })}</p>

      {/* Metric cards */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
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
      </div>
    </div>
  );
}
