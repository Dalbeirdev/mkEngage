"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery } from "@tanstack/react-query";

import { IconBot, IconContacts, IconMessages, IconStar } from "@/components/icons";
import { MetricCard, cardShell } from "@/components/metric-card";
import { conversationSchema, liveVisitorsSchema, type LiveVisitor } from "@/lib/api/schemas";

async function fetchLiveVisitors() {
  const res = await fetch("/api/cp/visitors/live", { cache: "no-store" });
  if (!res.ok) throw new Error(`Failed to load visitors (${res.status})`);
  return liveVisitorsSchema.parse(await res.json()).data;
}

async function startConversation(payload: { visitor_id: string; message: string }) {
  const res = await fetch("/api/cp/conversations", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  if (!res.ok) throw new Error(`Start failed (${res.status})`);
  return conversationSchema.parse(await res.json());
}

function timeOnSite(firstSeen: string | null): string {
  if (firstSeen === null) return "—";
  const s = Math.max(0, Math.round((Date.now() - new Date(firstSeen).getTime()) / 1000));
  if (s < 60) return `${s}s`;
  if (s < 3600) return `${Math.floor(s / 60)}m ${s % 60}s`;
  return `${Math.floor(s / 3600)}h ${Math.floor((s % 3600) / 60)}m`;
}

const bucketClass: Record<string, string> = {
  hot: "bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300",
  warm: "bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200",
  cold: "bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400",
};

function VisitorRow({ visitor }: { visitor: LiveVisitor }) {
  const router = useRouter();
  const [message, setMessage] = useState("");
  const [composerOpen, setComposerOpen] = useState(false);

  const start = useMutation({
    mutationFn: startConversation,
    onSuccess: (conversation) => router.push(`/conversations/${conversation.conversation_id}`),
  });

  const name = visitor.contact_name ?? visitor.display_name ?? "Anonymous visitor";

  return (
    <li className="flex flex-col gap-2 border-b border-zinc-100 py-3 last:border-0 dark:border-zinc-800">
      <div className="flex items-center gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300" aria-hidden>
          {name.slice(0, 1).toUpperCase()}
        </span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-medium">
            {name}
            {visitor.contact_email !== null && <span className="ms-2 text-xs font-normal text-zinc-500">{visitor.contact_email}</span>}
          </p>
          <p className="truncate text-xs text-zinc-500">
            {visitor.location != null && visitor.location !== "" && (
              <span className="me-1">📍 {visitor.location} ·</span>
            )}
            {visitor.page_title ?? visitor.current_url ?? "Unknown page"}
            <span className="ms-2">· on site {timeOnSite(visitor.first_seen_at)}</span>
          </p>
        </div>
        <span className={`inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${bucketClass[visitor.lead_bucket]}`} title={`Lead score ${visitor.lead_score}`}>
          {visitor.lead_bucket === "hot" ? "🔥" : visitor.lead_bucket === "warm" ? "☀️" : "❄️"}
          {visitor.lead_score}
        </span>
        {visitor.conversation_id !== null ? (
          <button
            type="button"
            onClick={() => router.push(`/conversations/${visitor.conversation_id}`)}
            className="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
          >
            Open chat
          </button>
        ) : (
          <button
            type="button"
            onClick={() => setComposerOpen((v) => !v)}
            className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500"
          >
            Start chat
          </button>
        )}
      </div>

      {composerOpen && visitor.conversation_id === null && (
        <form
          className="flex items-center gap-2 ps-12"
          onSubmit={(e) => {
            e.preventDefault();
            if (message.trim() === "" || start.isPending) return;
            start.mutate({ visitor_id: visitor.visitor_id, message: message.trim() });
          }}
        >
          <input
            type="text"
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            placeholder="Type a proactive message…"
            className="min-w-0 flex-1 rounded-lg border border-zinc-200 px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-950"
          />
          <button type="submit" disabled={start.isPending || message.trim() === ""} className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500 disabled:opacity-60">
            {start.isPending ? "Sending…" : "Send"}
          </button>
          {start.isError && <span className="text-xs text-red-600" role="alert">Couldn’t start the chat.</span>}
        </form>
      )}
    </li>
  );
}

export default function VisitorsPage() {
  const { data, isPending, isError } = useQuery({
    queryKey: ["live-visitors"],
    queryFn: fetchLiveVisitors,
    refetchInterval: 5000,
    refetchIntervalInBackground: true,
  });

  const rows = useMemo(() => data ?? [], [data]);

  const stats = useMemo(() => {
    const engaged = rows.filter((v) => v.conversation_id !== null).length;
    const hot = rows.filter((v) => v.lead_bucket === "hot").length;
    const avg = rows.length === 0 ? 0 : Math.round(rows.reduce((s, v) => s + v.lead_score, 0) / rows.length);
    return { online: rows.length, engaged, hot, avg };
  }, [rows]);

  const topPages = useMemo(() => {
    const map = new Map<string, number>();
    for (const v of rows) {
      const key = v.current_url ?? v.page_title ?? "Unknown";
      map.set(key, (map.get(key) ?? 0) + 1);
    }
    const total = rows.length || 1;
    return [...map.entries()]
      .map(([url, count]) => ({ url, count, pct: Math.round((count / total) * 100) }))
      .sort((a, b) => b.count - a.count)
      .slice(0, 5);
  }, [rows]);

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <h1 className="text-2xl font-bold tracking-tight">Live Visitors</h1>
        <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
          <span aria-hidden className="size-1.5 animate-pulse rounded-full bg-emerald-500" />
          {stats.online} online
        </span>
      </div>
      <p className="-mt-4 text-sm text-zinc-500">People on your site right now. Start a conversation before they leave.</p>

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <MetricCard icon={<IconContacts />} tint="indigo" label="Visitors Online" value={stats.online.toLocaleString()} caption="right now" />
        <MetricCard icon={<IconMessages />} tint="emerald" label="Engaged" value={stats.engaged.toLocaleString()} caption="in a conversation" />
        <MetricCard icon={<IconStar />} tint="amber" label="Hot Leads" value={stats.hot.toLocaleString()} caption="score ≥ 60" />
        <MetricCard icon={<IconBot />} tint="sky" label="Avg Lead Score" value={String(stats.avg)} caption="across online visitors" />
      </div>

      {isPending && <p className="text-sm text-zinc-500" role="status">Loading live visitors…</p>}
      {isError && <p className="text-sm text-red-600" role="alert">Couldn’t load visitors.</p>}

      {data !== undefined && (
        <div className="grid gap-6 lg:grid-cols-3">
          <section className={`lg:col-span-2 ${cardShell}`}>
            <h2 className="mb-3 text-sm font-semibold">Live Visitors</h2>
            {rows.length === 0 ? (
              <p className="py-6 text-center text-sm text-zinc-500">No visitors online right now.</p>
            ) : (
              <ul>
                {rows.map((v) => (
                  <VisitorRow key={v.visitor_id} visitor={v} />
                ))}
              </ul>
            )}
          </section>

          <section className={cardShell}>
            <h2 className="mb-3 text-sm font-semibold">Top Pages</h2>
            {topPages.length === 0 ? (
              <p className="py-6 text-center text-sm text-zinc-500">No page data yet.</p>
            ) : (
              <ul className="space-y-3">
                {topPages.map((p) => (
                  <li key={p.url} className="text-sm">
                    <div className="mb-1 flex items-center justify-between gap-2">
                      <span className="min-w-0 truncate text-zinc-600 dark:text-zinc-300">{p.url}</span>
                      <span className="shrink-0 tabular-nums text-zinc-500">
                        {p.count} <span className="text-zinc-400">({p.pct}%)</span>
                      </span>
                    </div>
                    <div className="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
                      <div className="h-2 rounded-full bg-indigo-500" style={{ width: `${p.pct}%` }} />
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      )}
    </div>
  );
}
