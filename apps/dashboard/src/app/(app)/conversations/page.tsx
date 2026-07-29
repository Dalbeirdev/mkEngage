"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  IconArrowRight,
  IconBell,
  IconChatbots,
  IconClock,
  IconConversations,
  IconSearch,
  IconStar,
} from "@/components/icons";
import { MetricCard, cardShell } from "@/components/metric-card";
import {
  conversationListSchema,
  departmentListSchema,
  savedViewListSchema,
  type Conversation,
  type SavedView,
} from "@/lib/api/schemas";

const PAGE_SIZE = 15;

type Tab = "all" | "open" | "pending" | "closed" | "unassigned" | "spam";

const CHANNEL_META: Record<string, { label: string; dot: string }> = {
  web: { label: "Website", dot: "bg-indigo-500" },
  whatsapp: { label: "WhatsApp", dot: "bg-emerald-500" },
  telegram: { label: "Telegram", dot: "bg-sky-500" },
  messenger: { label: "Messenger", dot: "bg-blue-500" },
  instagram: { label: "Instagram", dot: "bg-pink-500" },
  email: { label: "Email", dot: "bg-violet-500" },
};

function channelMeta(key: string | null | undefined) {
  const k = key ?? "web";
  return CHANNEL_META[k] ?? { label: k, dot: "bg-zinc-400" };
}

// Ticket priority chips. Normal is the quiet default (no chip); the rest stand out.
const PRIORITY_META: Record<string, { label: string; cls: string }> = {
  urgent: { label: "Urgent", cls: "bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300" },
  high: { label: "High", cls: "bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300" },
  low: { label: "Low", cls: "bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400" },
};

function relativeTime(iso: string | null): string {
  if (iso === null) return "";
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return "";
  const m = Math.floor(Math.max(0, Date.now() - then) / 60000);
  if (m < 1) return "Now";
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h ago`;
  return `${Math.floor(h / 24)}d ago`;
}

function convName(c: Conversation): string {
  return c.contact_name ?? c.visitor_name ?? "Anonymous visitor";
}

async function fetchConversations(departmentId: string, channel: string, search: string, priority: string, spamOnly: boolean) {
  const params = new URLSearchParams({ status: "all", limit: "100" });
  if (departmentId !== "all") params.set("department_id", departmentId);
  if (channel !== "all") params.set("channel", channel);
  if (priority !== "all") params.set("priority", priority);
  if (spamOnly) params.set("spam", "only");
  if (search.trim() !== "") params.set("search", search.trim());
  const res = await fetch(`/api/cp/conversations?${params.toString()}`, { cache: "no-store" });
  if (!res.ok) throw new Error(`Failed to load conversations (${res.status})`);
  return conversationListSchema.parse(await res.json()).data;
}

async function fetchDepartments() {
  const res = await fetch("/api/cp/departments", { cache: "no-store" });
  if (!res.ok) throw new Error(`Failed to load departments (${res.status})`);
  return departmentListSchema.parse(await res.json()).data;
}

async function fetchViews() {
  const res = await fetch("/api/cp/saved-views", { cache: "no-store" });
  if (!res.ok) throw new Error(`Failed to load views (${res.status})`);
  return savedViewListSchema.parse(await res.json()).data;
}

async function createView(name: string, filters: SavedView["filters"]) {
  const res = await fetch("/api/cp/saved-views", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ name, filters }),
  });
  if (!res.ok) throw new Error(`Save view failed (${res.status})`);
}

async function deleteView(id: string) {
  const res = await fetch(`/api/cp/saved-views/${id}`, { method: "DELETE" });
  if (!res.ok) throw new Error(`Delete view failed (${res.status})`);
}

/**
 * Conversation inbox (§3). Polls via TanStack Query; the render path is
 * unchanged when the gateway subscription lands (ADR-002).
 */
export default function ConversationsPage() {
  const [tab, setTab] = useState<Tab>("all");
  const [search, setSearch] = useState("");
  const [channel, setChannel] = useState("all");
  const [departmentId, setDepartmentId] = useState("all");
  const [priority, setPriority] = useState("all");
  const [page, setPage] = useState(1);

  const [debouncedSearch, setDebouncedSearch] = useState("");
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 350);
    return () => clearTimeout(timer);
  }, [search]);

  const spamView = tab === "spam";
  const { data, isPending, isError } = useQuery({
    queryKey: ["conversations", departmentId, channel, priority, spamView, debouncedSearch],
    queryFn: () => fetchConversations(departmentId, channel, debouncedSearch, priority, spamView),
    refetchInterval: 5000,
    refetchIntervalInBackground: true,
  });

  const departments = useQuery({ queryKey: ["departments"], queryFn: fetchDepartments });

  const queryClient = useQueryClient();
  const views = useQuery({ queryKey: ["saved-views"], queryFn: fetchViews });
  const invalidateViews = () => void queryClient.invalidateQueries({ queryKey: ["saved-views"] });
  const saveView = useMutation({
    mutationFn: ({ name, filters }: { name: string; filters: SavedView["filters"] }) => createView(name, filters),
    onSuccess: invalidateViews,
  });
  const removeView = useMutation({ mutationFn: deleteView, onSuccess: invalidateViews });

  function applyView(v: SavedView) {
    setTab((v.filters.tab as Tab | undefined) ?? "all");
    setChannel(v.filters.channel ?? "all");
    setPriority(v.filters.priority ?? "all");
    setDepartmentId(v.filters.department_id ?? "all");
    setSearch(v.filters.search ?? "");
    setPage(1);
  }

  function saveCurrentView() {
    const name = window.prompt("Name this view")?.trim();
    if (name === undefined || name === "") return;
    saveView.mutate({
      name,
      filters: {
        tab,
        ...(channel !== "all" ? { channel } : {}),
        ...(priority !== "all" ? { priority } : {}),
        ...(departmentId !== "all" ? { department_id: departmentId } : {}),
        ...(search.trim() !== "" ? { search: search.trim() } : {}),
      },
    });
  }

  const rows = useMemo(() => data ?? [], [data]);

  const counts = useMemo(() => {
    const c = { all: rows.length, open: 0, pending: 0, closed: 0, unassigned: 0 };
    for (const r of rows) {
      if (r.status === "open") c.open += 1;
      else if (r.status === "pending") c.pending += 1;
      else if (r.status === "closed") c.closed += 1;
      if (r.assigned_agent_id === null) c.unassigned += 1;
    }
    return c;
  }, [rows]);

  const csat = useMemo(() => {
    const rated = rows.map((r) => r.csat_rating).filter((v): v is number => typeof v === "number");
    if (rated.length === 0) return null;
    return { avg: rated.reduce((s, v) => s + v, 0) / rated.length, count: rated.length };
  }, [rows]);

  const filtered = useMemo(() => {
    // In the Spam view the server already returned spam-only rows.
    if (tab === "all" || tab === "spam") return rows;
    if (tab === "unassigned") return rows.filter((r) => r.assigned_agent_id === null);
    return rows.filter((r) => r.status === tab);
  }, [rows, tab]);

  // Reset to page 1 whenever the filters change — adjust state during render
  // (React's recommended pattern) rather than in an effect.
  const filterKey = `${tab}|${channel}|${departmentId}|${debouncedSearch}`;
  const [prevFilterKey, setPrevFilterKey] = useState(filterKey);
  if (filterKey !== prevFilterKey) {
    setPrevFilterKey(filterKey);
    setPage(1);
  }

  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const current = Math.min(page, totalPages);
  const pageRows = filtered.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE);

  function exportCsv() {
    const header = ["Visitor", "Channel", "Department", "Agent", "Last message", "Status", "Messages", "Updated"];
    const lines = filtered.map((r) =>
      [
        convName(r),
        channelMeta(r.channel_type).label,
        r.department_name ?? "",
        r.assigned_agent_name ?? "Unassigned",
        (r.last_message ?? "").replace(/\s+/g, " "),
        r.status,
        String(r.last_sequence),
        r.updated_at ?? "",
      ]
        .map((f) => `"${String(f).replace(/"/g, '""')}"`)
        .join(","),
    );
    const blob = new Blob([[header.join(","), ...lines].join("\n")], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = "conversations.csv";
    a.click();
    URL.revokeObjectURL(url);
  }

  const tabs: { key: Tab; label: string; count: number | null }[] = [
    { key: "all", label: "All", count: spamView ? null : counts.all },
    { key: "open", label: "Open", count: spamView ? null : counts.open },
    { key: "pending", label: "Pending", count: spamView ? null : counts.pending },
    { key: "closed", label: "Closed", count: spamView ? null : counts.closed },
    { key: "unassigned", label: "Unassigned", count: spamView ? null : counts.unassigned },
    // Spam count is only known while the Spam view is open (server-scoped).
    { key: "spam", label: "Spam", count: spamView ? counts.all : null },
  ];

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
            Conversations
            <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-sm font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">
              {counts.all}
            </span>
          </h1>
          <p className="mt-1 text-sm text-zinc-500">
            All incoming conversations from your visitors across all channels.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={exportCsv}
            className="rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium transition-colors hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
          >
            Export
          </button>
          <Link
            href="/visitors"
            className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-500"
          >
            New Conversation
            <IconArrowRight />
          </Link>
        </div>
      </div>

      {/* Metric cards */}
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <MetricCard icon={<IconConversations />} tint="indigo" label="Total Conversations" value={counts.all.toLocaleString()} caption="in view" />
        <MetricCard icon={<IconChatbots />} tint="emerald" label="Open" value={counts.open.toLocaleString()} caption="need attention" />
        <MetricCard icon={<IconClock />} tint="amber" label="Pending" value={counts.pending.toLocaleString()} caption="awaiting reply" />
        <MetricCard icon={<IconBell />} tint="sky" label="Closed" value={counts.closed.toLocaleString()} caption="resolved" />
        <MetricCard icon={<IconStar />} tint="pink" label="CSAT Score" value={csat !== null ? csat.avg.toFixed(1) : "—"} stars={csat?.avg ?? null} caption={csat !== null ? `Based on ${csat.count} rating${csat.count === 1 ? "" : "s"}` : "no ratings yet"} />
      </div>

      {/* Toolbar: tabs + filters */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800/60">
          {tabs.map((tb) => (
            <button
              key={tb.key}
              type="button"
              onClick={() => setTab(tb.key)}
              aria-pressed={tab === tb.key}
              className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
                tab === tb.key
                  ? "bg-white text-indigo-700 shadow-sm dark:bg-zinc-900 dark:text-indigo-300"
                  : "text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
              }`}
            >
              {tb.label}
              {tb.count !== null && <span className="ms-1.5 text-xs text-zinc-400">{tb.count}</span>}
            </button>
          ))}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <div className="relative">
            <span className="pointer-events-none absolute inset-y-0 start-2.5 grid place-items-center text-zinc-400" aria-hidden>
              <IconSearch />
            </span>
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search name or message"
              aria-label="Search conversations"
              className="w-56 rounded-lg border border-zinc-200 bg-white py-1.5 ps-9 pe-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
            />
          </div>
          <select
            value={channel}
            onChange={(e) => setChannel(e.target.value)}
            aria-label="Filter by channel"
            className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
          >
            <option value="all">All channels</option>
            <option value="web">Website</option>
            <option value="whatsapp">WhatsApp</option>
            <option value="telegram">Telegram</option>
            <option value="messenger">Messenger</option>
            <option value="instagram">Instagram</option>
            <option value="email">Email</option>
          </select>
          <select
            value={priority}
            onChange={(e) => setPriority(e.target.value)}
            aria-label="Filter by priority"
            className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
          >
            <option value="all">All priorities</option>
            <option value="urgent">Urgent</option>
            <option value="high">High</option>
            <option value="normal">Normal</option>
            <option value="low">Low</option>
          </select>
          {departments.data !== undefined && departments.data.length > 0 && (
            <select
              value={departmentId}
              onChange={(e) => setDepartmentId(e.target.value)}
              aria-label="Filter by department"
              className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
            >
              <option value="all">All departments</option>
              {departments.data.map((d) => (
                <option key={d.department_id} value={d.department_id}>
                  {d.name}
                </option>
              ))}
            </select>
          )}
        </div>
      </div>

      {/* Saved views */}
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-xs font-medium text-zinc-500">Views:</span>
        {(views.data ?? []).map((v) => (
          <span
            key={v.saved_view_id}
            className="inline-flex items-center gap-1 rounded-full border border-zinc-200 bg-white py-1 ps-3 pe-1.5 text-xs font-medium dark:border-zinc-700 dark:bg-zinc-900"
          >
            <button type="button" onClick={() => applyView(v)} className="hover:text-indigo-600 dark:hover:text-indigo-400">
              {v.name}
            </button>
            <button
              type="button"
              aria-label={`Delete view ${v.name}`}
              onClick={() => removeView.mutate(v.saved_view_id)}
              className="grid size-4 place-items-center rounded-full text-zinc-400 hover:bg-zinc-100 hover:text-red-600 dark:hover:bg-zinc-800"
            >
              ✕
            </button>
          </span>
        ))}
        {(views.data ?? []).length === 0 && <span className="text-xs text-zinc-400">none yet</span>}
        <button
          type="button"
          onClick={saveCurrentView}
          disabled={saveView.isPending}
          className="rounded-full border border-dashed border-zinc-300 px-3 py-1 text-xs font-medium text-zinc-600 hover:bg-zinc-100 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
        >
          + Save current view
        </button>
      </div>

      {/* Table */}
      <div className={`overflow-hidden p-0 ${cardShell}`}>
        {isPending && <p className="p-6 text-sm text-zinc-500" role="status">Loading conversations…</p>}
        {isError && <p className="p-6 text-sm text-red-600" role="alert">Couldn’t load conversations.</p>}
        {!isPending && !isError && filtered.length === 0 && (
          <p className="p-6 text-sm text-zinc-500">No conversations match these filters.</p>
        )}

        {!isPending && filtered.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[900px] text-sm">
              <thead>
                <tr className="border-b border-zinc-200 text-start text-xs font-medium text-zinc-500 dark:border-zinc-800">
                  <th className="px-4 py-3 text-start font-medium">Visitor</th>
                  <th className="px-4 py-3 text-start font-medium">Channel</th>
                  <th className="px-4 py-3 text-start font-medium">Department</th>
                  <th className="px-4 py-3 text-start font-medium">Agent</th>
                  <th className="px-4 py-3 text-start font-medium">Last message</th>
                  <th className="px-4 py-3 text-start font-medium">Status</th>
                  <th className="px-4 py-3 text-end font-medium">Msgs</th>
                  <th className="px-4 py-3 text-end font-medium">Time</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                {pageRows.map((c) => {
                  const meta = channelMeta(c.channel_type);
                  const name = convName(c);
                  const unread = (c.unread_count ?? 0) > 0;
                  return (
                    <tr key={c.conversation_id} className="transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                      <td className="px-4 py-3">
                        <Link href={`/conversations/${c.conversation_id}`} className="flex items-center gap-3 focus-visible:outline-none">
                          <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">
                            {name.charAt(0).toUpperCase()}
                          </span>
                          <span className="min-w-0">
                            <span className={`flex items-center gap-1.5 truncate ${unread ? "font-bold" : "font-medium"}`}>
                              {name}
                              {unread && (
                                <span className="inline-grid min-w-4 place-items-center rounded-full bg-indigo-600 px-1 text-[10px] font-bold text-white">
                                  {c.unread_count}
                                </span>
                              )}
                              {PRIORITY_META[c.priority] !== undefined && (
                                <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${PRIORITY_META[c.priority].cls}`}>
                                  {PRIORITY_META[c.priority].label}
                                </span>
                              )}
                            </span>
                            {c.last_message !== null && c.last_message !== undefined && (
                              <span className="block max-w-[220px] truncate text-xs text-zinc-400">{c.last_message}</span>
                            )}
                          </span>
                        </Link>
                      </td>
                      <td className="px-4 py-3">
                        <span className="inline-flex items-center gap-1.5 text-zinc-600 dark:text-zinc-300">
                          <span className={`size-2 rounded-full ${meta.dot}`} aria-hidden />
                          {meta.label}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        {c.department_name !== null ? (
                          <span className="rounded-md bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            {c.department_name}
                          </span>
                        ) : (
                          <span className="text-zinc-400">—</span>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        {c.assigned_agent_name != null ? (
                          <span className="text-zinc-600 dark:text-zinc-300">{c.assigned_agent_name}</span>
                        ) : (
                          <span className="text-zinc-400">Unassigned</span>
                        )}
                      </td>
                      <td className="max-w-[260px] px-4 py-3">
                        <span className="block truncate text-zinc-500">{c.last_message ?? "—"}</span>
                      </td>
                      <td className="px-4 py-3">
                        <span
                          className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                            c.status === "open"
                              ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                              : c.status === "pending"
                                ? "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                                : "bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                          }`}
                        >
                          {c.status[0].toUpperCase() + c.status.slice(1)}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-end tabular-nums text-zinc-500">{c.last_sequence}</td>
                      <td className="px-4 py-3 text-end whitespace-nowrap text-zinc-400">{relativeTime(c.updated_at)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination */}
        {!isPending && filtered.length > 0 && (
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 px-4 py-3 text-sm dark:border-zinc-800">
            <span className="text-zinc-500">
              Showing {(current - 1) * PAGE_SIZE + 1} to {Math.min(current * PAGE_SIZE, filtered.length)} of {filtered.length}
            </span>
            <div className="flex items-center gap-1">
              <button
                type="button"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={current === 1}
                className="rounded-md border border-zinc-200 px-2.5 py-1 disabled:opacity-40 dark:border-zinc-700"
              >
                Prev
              </button>
              {Array.from({ length: totalPages }, (_, i) => i + 1)
                .filter((p) => p === 1 || p === totalPages || Math.abs(p - current) <= 1)
                .map((p, idx, arr) => (
                  <span key={p} className="flex items-center">
                    {idx > 0 && arr[idx - 1] !== p - 1 && <span className="px-1 text-zinc-400">…</span>}
                    <button
                      type="button"
                      onClick={() => setPage(p)}
                      aria-current={p === current ? "page" : undefined}
                      className={`min-w-8 rounded-md px-2.5 py-1 ${
                        p === current
                          ? "bg-indigo-600 font-semibold text-white"
                          : "border border-zinc-200 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                      }`}
                    >
                      {p}
                    </button>
                  </span>
                ))}
              <button
                type="button"
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                disabled={current === totalPages}
                className="rounded-md border border-zinc-200 px-2.5 py-1 disabled:opacity-40 dark:border-zinc-700"
              >
                Next
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
