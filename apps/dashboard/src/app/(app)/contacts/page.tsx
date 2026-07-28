"use client";

import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { IconChatbots, IconContacts, IconMessages, IconStar } from "@/components/icons";
import { MetricCard, cardShell } from "@/components/metric-card";
import { contactListSchema, type Contact } from "@/lib/api/schemas";

const PAGE_SIZE = 10;

type Tab = "all" | "email" | "chat";

async function fetchContacts() {
  const res = await fetch("/api/cp/contacts", { cache: "no-store" });
  if (!res.ok) throw new Error(`Failed to load contacts (${res.status})`);
  return contactListSchema.parse(await res.json()).data;
}

/** Channel a contact reached us through, inferred from their external id. */
function contactChannel(c: Contact): { label: string; dot: string } {
  const id = c.external_id ?? "";
  if (id.startsWith("tg:")) return { label: "Telegram", dot: "bg-sky-500" };
  if (id.startsWith("fb:")) return { label: "Messenger", dot: "bg-blue-500" };
  if (id.startsWith("wa:")) return { label: "WhatsApp", dot: "bg-emerald-500" };
  if (c.email !== null && c.email !== "") return { label: "Email", dot: "bg-violet-500" };
  return { label: "Website", dot: "bg-indigo-500" };
}

function isThisMonth(iso: string | null): boolean {
  if (iso === null) return false;
  const d = new Date(iso);
  const now = new Date();
  return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth();
}

export default function ContactsPage() {
  const [tab, setTab] = useState<Tab>("all");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);

  const { data, isPending, isError } = useQuery({
    queryKey: ["contacts"],
    queryFn: fetchContacts,
    refetchInterval: 15000,
    refetchIntervalInBackground: true,
  });

  const rows = useMemo(() => data ?? [], [data]);

  const counts = useMemo(() => {
    let email = 0;
    let chat = 0;
    let month = 0;
    for (const c of rows) {
      if (c.email !== null && c.email !== "") email += 1;
      if (c.external_id !== null && c.external_id !== "") chat += 1;
      if (isThisMonth(c.created_at)) month += 1;
    }
    return { total: rows.length, email, chat, month };
  }, [rows]);

  const filtered = useMemo(() => {
    let list = rows;
    if (tab === "email") list = list.filter((c) => c.email !== null && c.email !== "");
    else if (tab === "chat") list = list.filter((c) => c.external_id !== null && c.external_id !== "");
    const q = search.trim().toLowerCase();
    if (q !== "") {
      list = list.filter((c) =>
        [c.name, c.email, c.external_id].some((f) => (f ?? "").toLowerCase().includes(q)),
      );
    }
    return list;
  }, [rows, tab, search]);

  const filterKey = `${tab}|${search}`;
  const [prevKey, setPrevKey] = useState(filterKey);
  if (filterKey !== prevKey) {
    setPrevKey(filterKey);
    setPage(1);
  }

  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const current = Math.min(page, totalPages);
  const pageRows = filtered.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE);

  function exportCsv() {
    const header = ["Name", "Email", "External ID", "Channel", "Created"];
    const lines = filtered.map((c) =>
      [c.name ?? "", c.email ?? "", c.external_id ?? "", contactChannel(c).label, c.created_at ?? ""]
        .map((f) => `"${String(f).replace(/"/g, '""')}"`)
        .join(","),
    );
    const blob = new Blob([[header.join(","), ...lines].join("\n")], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = "contacts.csv";
    a.click();
    URL.revokeObjectURL(url);
  }

  const tabs: { key: Tab; label: string; count: number }[] = [
    { key: "all", label: "All", count: counts.total },
    { key: "email", label: "Email", count: counts.email },
    { key: "chat", label: "Chat", count: counts.chat },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
            Contacts
            <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-sm font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">
              {counts.total}
            </span>
          </h1>
          <p className="mt-1 text-sm text-zinc-500">
            View and manage all your contacts across channels.
          </p>
        </div>
        <button
          type="button"
          onClick={exportCsv}
          className="rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium transition-colors hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
        >
          Export
        </button>
      </div>

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <MetricCard icon={<IconContacts />} tint="indigo" label="Total Contacts" value={counts.total.toLocaleString()} caption="all contacts" />
        <MetricCard icon={<IconMessages />} tint="emerald" label="Email Contacts" value={counts.email.toLocaleString()} caption="have an email" />
        <MetricCard icon={<IconChatbots />} tint="sky" label="Chat Contacts" value={counts.chat.toLocaleString()} caption="from a channel" />
        <MetricCard icon={<IconStar />} tint="amber" label="New This Month" value={counts.month.toLocaleString()} caption="joined recently" />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800/60">
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
              <span className="ms-1.5 text-xs text-zinc-400">{tb.count}</span>
            </button>
          ))}
        </div>
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search by name, email or external ID…"
          aria-label="Search contacts"
          className="w-72 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
        />
      </div>

      <div className={`overflow-hidden p-0 ${cardShell}`}>
        {isPending && <p className="p-6 text-sm text-zinc-500" role="status">Loading contacts…</p>}
        {isError && <p className="p-6 text-sm text-red-600" role="alert">Couldn’t load contacts.</p>}
        {!isPending && !isError && filtered.length === 0 && (
          <p className="p-6 text-sm text-zinc-500">No contacts match these filters.</p>
        )}

        {!isPending && filtered.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px] text-sm">
              <thead>
                <tr className="border-b border-zinc-200 text-xs font-medium text-zinc-500 dark:border-zinc-800">
                  <th className="px-4 py-3 text-start font-medium">Name</th>
                  <th className="px-4 py-3 text-start font-medium">Email</th>
                  <th className="px-4 py-3 text-start font-medium">External ID</th>
                  <th className="px-4 py-3 text-start font-medium">Channel</th>
                  <th className="px-4 py-3 text-start font-medium">Created</th>
                  <th className="px-4 py-3 text-start font-medium">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                {pageRows.map((c) => {
                  const meta = contactChannel(c);
                  const name = c.name ?? "Unnamed";
                  return (
                    <tr key={c.contact_id} className="transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                      <td className="px-4 py-3">
                        <span className="flex items-center gap-3">
                          <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">
                            {name.charAt(0).toUpperCase()}
                          </span>
                          <span className="font-medium">{name}</span>
                        </span>
                      </td>
                      <td className="px-4 py-3 text-zinc-500">{c.email ?? "—"}</td>
                      <td className="px-4 py-3 font-mono text-xs text-zinc-500">{c.external_id ?? "—"}</td>
                      <td className="px-4 py-3">
                        <span className="inline-flex items-center gap-1.5 text-zinc-600 dark:text-zinc-300">
                          <span className={`size-2 rounded-full ${meta.dot}`} aria-hidden />
                          {meta.label}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-zinc-500">
                        {c.created_at === null ? "—" : new Date(c.created_at).toLocaleDateString()}
                      </td>
                      <td className="px-4 py-3">
                        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                          Active
                        </span>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {!isPending && filtered.length > 0 && (
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 px-4 py-3 text-sm dark:border-zinc-800">
            <span className="text-zinc-500">
              Showing {(current - 1) * PAGE_SIZE + 1} to {Math.min(current * PAGE_SIZE, filtered.length)} of {filtered.length} contacts
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
