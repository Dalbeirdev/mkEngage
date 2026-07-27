"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { useState } from "react";

import { conversationListSchema, departmentListSchema } from "@/lib/api/schemas";
import { card, emptyState, pageTitle } from "@/lib/ui";

async function fetchConversations(departmentId: string) {
  const query = departmentId === "all" ? "" : `&department_id=${departmentId}`;
  const response = await fetch(`/api/cp/conversations?status=all${query}`, { cache: "no-store" });
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

  const { data, isPending, isError } = useQuery({
    queryKey: ["conversations", departmentId],
    queryFn: () => fetchConversations(departmentId),
    refetchInterval: 5000,
    // Agent console must stay current even when the tab is hidden.
    refetchIntervalInBackground: true,
  });

  const departments = useQuery({ queryKey: ["departments"], queryFn: fetchDepartments });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-4">
        <h1 className={pageTitle}>{t("title")}</h1>
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
                    <span className="block truncate font-medium">
                      {name}
                      {conversation.channel_type === "whatsapp" && (
                        <span className="ms-2 rounded-full bg-green-100 px-1.5 py-0.5 text-[10px] font-semibold text-green-800 dark:bg-green-900/40 dark:text-green-300">
                          WhatsApp
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
