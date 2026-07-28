"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { BrandMark } from "@/components/brand-logo";
import { IconBot, IconChatbots, IconClock, IconStar } from "@/components/icons";
import { MetricCard, cardShell } from "@/components/metric-card";
import { chatbotListSchema, chatbotSchema, type ChatbotConfig } from "@/lib/api/schemas";

const PAGE_SIZE = 10;

type StatusFilter = "all" | "active" | "paused" | "draft";

async function fetchChatbots() {
  const res = await fetch("/api/cp/chatbots", { cache: "no-store" });
  if (!res.ok) throw new Error(`Failed to load chatbots (${res.status})`);
  return chatbotListSchema.parse(await res.json()).data;
}

async function createChatbot(name: string) {
  const res = await fetch("/api/cp/chatbots", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ name }),
  });
  if (!res.ok) throw new Error(`Create failed (${res.status})`);
  return chatbotSchema.parse(await res.json());
}

const statusStyles: Record<string, string> = {
  active: "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300",
  draft: "bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300",
  paused: "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300",
};

function fmtDate(iso: string | null): string {
  if (iso === null) return "—";
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "—" : d.toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
}

export default function ChatbotsPage() {
  const queryClient = useQueryClient();
  const [name, setName] = useState("");
  const [status, setStatus] = useState<StatusFilter>("all");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);

  const { data, isPending, isError } = useQuery({ queryKey: ["chatbots"], queryFn: fetchChatbots });

  const create = useMutation({
    mutationFn: createChatbot,
    onSuccess: () => {
      setName("");
      void queryClient.invalidateQueries({ queryKey: ["chatbots"] });
    },
  });

  const submitCreate = () => {
    const trimmed = name.trim();
    if (trimmed.length > 0 && !create.isPending) create.mutate(trimmed);
  };

  const rows = useMemo(() => data ?? [], [data]);

  const counts = useMemo(() => {
    let active = 0;
    let paused = 0;
    let draft = 0;
    for (const b of rows) {
      if (b.status === "active") active += 1;
      else if (b.status === "paused") paused += 1;
      else draft += 1;
    }
    return { total: rows.length, active, paused, draft };
  }, [rows]);

  const filtered = useMemo(() => {
    let list = rows;
    if (status !== "all") list = list.filter((b) => b.status === status);
    const q = search.trim().toLowerCase();
    if (q !== "") list = list.filter((b) => b.name.toLowerCase().includes(q));
    return list;
  }, [rows, status, search]);

  const filterKey = `${status}|${search}`;
  const [prevKey, setPrevKey] = useState(filterKey);
  if (filterKey !== prevKey) {
    setPrevKey(filterKey);
    setPage(1);
  }

  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const current = Math.min(page, totalPages);
  const pageRows = filtered.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Chatbots</h1>
        <p className="mt-1 text-sm text-zinc-500">
          Create and manage AI chatbots to automate conversations and support your customers.
        </p>
      </div>

      {/* Create card */}
      <section className={`flex flex-wrap items-center gap-4 ${cardShell}`}>
        <div className="min-w-0 flex-1">
          <h2 className="text-sm font-semibold">Create a new chatbot</h2>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              submitCreate();
            }}
            className="mt-3 flex items-center gap-2"
            aria-label="Create a new chatbot"
          >
            <input
              type="text"
              value={name}
              maxLength={100}
              onChange={(e) => setName(e.target.value)}
              placeholder="Enter chatbot name…"
              aria-label="Chatbot name"
              className="min-w-0 flex-1 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
            />
            {/* type="button" + onClick so the click always fires, independent
                of native form-submit behaviour. */}
            <button
              type="button"
              onClick={submitCreate}
              disabled={create.isPending || name.trim().length === 0}
              className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {create.isPending ? "Creating…" : "Create"}
            </button>
          </form>
          {create.isError && <p className="mt-2 text-xs text-red-600" role="alert">Couldn’t create that chatbot.</p>}
        </div>
        <BrandMark className="hidden h-16 w-auto sm:block" />
      </section>

      {/* Metric cards */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <MetricCard icon={<IconBot />} tint="indigo" label="Total Chatbots" value={counts.total.toLocaleString()} caption="all chatbots" />
        <MetricCard icon={<IconChatbots />} tint="emerald" label="Active" value={counts.active.toLocaleString()} caption="serving now" />
        <MetricCard icon={<IconClock />} tint="amber" label="Paused" value={counts.paused.toLocaleString()} caption="temporarily off" />
        <MetricCard icon={<IconStar />} tint="sky" label="Draft" value={counts.draft.toLocaleString()} caption="not launched" />
      </div>

      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-2">
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search chatbots…"
          aria-label="Search chatbots"
          className="w-64 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
        />
        <select
          value={status}
          onChange={(e) => setStatus(e.target.value as StatusFilter)}
          aria-label="Filter by status"
          className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
        >
          <option value="all">All status</option>
          <option value="active">Active</option>
          <option value="paused">Paused</option>
          <option value="draft">Draft</option>
        </select>
      </div>

      {/* Table */}
      <div className={`overflow-hidden p-0 ${cardShell}`}>
        {isPending && <p className="p-6 text-sm text-zinc-500" role="status">Loading chatbots…</p>}
        {isError && <p className="p-6 text-sm text-red-600" role="alert">Couldn’t load chatbots.</p>}
        {!isPending && !isError && filtered.length === 0 && (
          <p className="p-6 text-sm text-zinc-500">No chatbots yet — create one above.</p>
        )}

        {!isPending && filtered.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="border-b border-zinc-200 text-xs font-medium text-zinc-500 dark:border-zinc-800">
                  <th className="px-4 py-3 text-start font-medium">Name</th>
                  <th className="px-4 py-3 text-start font-medium">Provider</th>
                  <th className="px-4 py-3 text-start font-medium">Status</th>
                  <th className="px-4 py-3 text-start font-medium">Created</th>
                  <th className="px-4 py-3 text-start font-medium">Updated</th>
                  <th className="px-4 py-3 text-end font-medium">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                {pageRows.map((b: ChatbotConfig) => (
                  <tr key={b.chatbot_id} className="transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                    <td className="px-4 py-3">
                      <Link href={`/chatbots/${b.chatbot_id}`} className="flex items-center gap-3">
                        <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300">
                          <IconBot />
                        </span>
                        <span className="font-medium">{b.name}</span>
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-zinc-500">
                      {b.provider}
                      {b.model !== null ? ` · ${b.model}` : ""}
                    </td>
                    <td className="px-4 py-3">
                      <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusStyles[b.status]}`}>
                        {b.status[0].toUpperCase() + b.status.slice(1)}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-zinc-500">{fmtDate(b.created_at)}</td>
                    <td className="px-4 py-3 text-zinc-500">{fmtDate(b.updated_at)}</td>
                    <td className="px-4 py-3 text-end">
                      <Link href={`/chatbots/${b.chatbot_id}`} className="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                        Edit
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {!isPending && filtered.length > 0 && (
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 px-4 py-3 text-sm dark:border-zinc-800">
            <span className="text-zinc-500">
              Showing {(current - 1) * PAGE_SIZE + 1} to {Math.min(current * PAGE_SIZE, filtered.length)} of {filtered.length} chatbots
            </span>
            <div className="flex items-center gap-1">
              <button type="button" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={current === 1} className="rounded-md border border-zinc-200 px-2.5 py-1 disabled:opacity-40 dark:border-zinc-700">
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
              <button type="button" onClick={() => setPage((p) => Math.min(totalPages, p + 1))} disabled={current === totalPages} className="rounded-md border border-zinc-200 px-2.5 py-1 disabled:opacity-40 dark:border-zinc-700">
                Next
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
