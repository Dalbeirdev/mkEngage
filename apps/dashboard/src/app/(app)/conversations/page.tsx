"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { useEffect, useState } from "react";

import { conversationListSchema, departmentListSchema } from "@/lib/api/schemas";
import { card, emptyState, pageTitle } from "@/lib/ui";

async function fetchConversations(departmentId: string, channel: string, search: string) {
  const params = new URLSearchParams({ status: "all" });
  if (departmentId !== "all") params.set("department_id", departmentId);
  if (channel !== "all") params.set("channel", channel);
  if (search.trim() !== "") params.set("search", search.trim());
  const response = await fetch(`/api/cp/conversations?${params.toString()}`, { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed to load conversations (${response.status})`);
  return conversationListSchema.parse(await response.json()).data;
}

async function fetchDepartments() {
  const response = await fetch("/api/cp/departments", { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed to load departments (${response.status})`);
  return departmentListSchema.parse(await response.json()).data;
}

/**
 * Conversation inbox (§3 real-time conversation updates). Polling via
 * TanStack Query until the gateway lands (ADR-002), then this becomes a
 * socket subscription with the same render path.
 */
export default function ConversationsPage() {
  const t = useTranslations("conversations");
  const [departmentId, setDepartmentId] = useState("all");
  const [channel, setChannel] = useState("all");
  const [search, setSearch] = useState("");
  // Debounce: the query key uses the settled value (350ms after typing).
  const [debouncedSearch, setDebouncedSearch] = useState("");
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 350);
    return () => clearTimeout(timer);
  }, [search]);

  const { data, isPending, isError } = useQuery({
    queryKey: ["conversations", departmentId, channel, debouncedSearch],
    queryFn: () => fetchConversations(departmentId, channel, debouncedSearch),
    refetchInterval: 5000,
    // Agent console must stay current even when the tab is hidden.
    refetchIntervalInBackground: true,
  });

  const departments = useQuery({ queryKey: ["departments"], queryFn: fetchDepartments });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-4">
        <h1 className={pageTitle}>{t("title")}</h1>
        <div className="flex items-center gap-2">
          <label htmlFor="inbox-search" className="sr-only">
            {t("searchLabel")}
          </label>
          <input
            id="inbox-search"
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={t("searchPlaceholder")}
            className="w-48 rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
          />
          <label htmlFor="channel-filter" className="sr-only">
            {t("channelFilterLabel")}
          </label>
          <select
            id="channel-filter"
            value={channel}
            onChange={(event) => setChannel(event.target.value)}
            className="rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
          >
            <option value="all">{t("allChannels")}</option>
            <option value="web">{t("channel_web")}</option>
            <option value="whatsapp">WhatsApp</option>
            <option value="telegram">Telegram</option>
            <option value="messenger">Messenger</option>
          </select>
        {departments.data !== undefined && departments.data.length > 0 && (
          <div>
            <label htmlFor="dept-filter" className="sr-only">
              {t("filterLabel")}
            </label>
            <select
              id="dept-filter"
              value={departmentId}
              onChange={(event) => setDepartmentId(event.target.value)}
              className="rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
            >
              <option value="all">{t("allDepartments")}</option>
              {departments.data.map((department) => (
                <option key={department.department_id} value={department.department_id}>
                  {department.name}
                </option>
              ))}
            </select>
          </div>
        )}
        </div>
      </div>

      {isPending && (
        <p className="text-sm text-zinc-500" role="status">
          {t("loading")}
        </p>
      )}
      {isError && (
        <p className="text-sm text-red-600" role="alert">
          {t("error")}
        </p>
      )}

      {data !== undefined && data.length === 0 && <div className={emptyState}>{t("empty")}</div>}

      {data !== undefined && data.length > 0 && (
        <ul className={`divide-y divide-zinc-200 overflow-hidden dark:divide-zinc-800 ${card}`}>
          {data.map((conversation) => {
            const name =
              conversation.contact_name ?? conversation.visitor_name ?? t("anonymousVisitor");
            return (
              <li key={conversation.conversation_id}>
                <Link
                  href={`/conversations/${conversation.conversation_id}`}
                  className="flex items-center gap-3 px-4 py-3 text-sm transition-colors hover:bg-zinc-50 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:hover:bg-zinc-800/50"
                >
                  <span
                    aria-hidden
                    className="grid size-9 shrink-0 place-items-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300"
                  >
                    {name.charAt(0).toUpperCase()}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className={`block truncate ${(conversation.unread_count ?? 0) > 0 ? "font-bold" : "font-medium"}`}>
                      {name}
                      {(conversation.unread_count ?? 0) > 0 && (
                        <span className="ms-2 inline-grid min-w-5 place-items-center rounded-full bg-indigo-600 px-1 text-[10px] font-bold text-white">
                          {conversation.unread_count}
                        </span>
                      )}
                      {conversation.channel_type != null && (
                        <span
                          className={`ms-2 rounded-full px-1.5 py-0.5 text-[10px] font-semibold capitalize ${
                            conversation.channel_type === "telegram"
                              ? "bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300"
                              : conversation.channel_type === "messenger"
                                ? "bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300"
                                : "bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300"
                          }`}
                        >
                          {conversation.channel_type}
                        </span>
                      )}
                    </span>
                    <span className="block truncate text-xs text-zinc-500">
                    {conversation.department_name !== null && (
                      <span className="me-2 rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        {conversation.department_name}
                      </span>
                    )}
                    {conversation.source_url ?? conversation.conversation_id}
                  </span>
                </span>
                <span className="flex shrink-0 items-center gap-3">
                  <span className="text-xs text-zinc-500">
                    {t("messageCount", { count: conversation.last_sequence })}
                  </span>
                  <span
                    className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                      conversation.status === "open"
                        ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                        : conversation.status === "pending"
                          ? "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                          : "bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                    }`}
                  >
                    {t(`status_${conversation.status}`)}
                  </span>
                </span>
              </Link>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
