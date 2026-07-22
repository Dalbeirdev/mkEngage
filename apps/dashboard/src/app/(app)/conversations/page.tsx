"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { conversationListSchema } from "@/lib/api/schemas";

async function fetchConversations() {
  const response = await fetch("/api/cp/conversations?status=all", { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed to load conversations (${response.status})`);
  return conversationListSchema.parse(await response.json()).data;
}

/**
 * Conversation inbox (§3 real-time conversation updates). Polling via
 * TanStack Query until the gateway lands (ADR-002), then this becomes a
 * socket subscription with the same render path.
 */
export default function ConversationsPage() {
  const t = useTranslations("conversations");

  const { data, isPending, isError } = useQuery({
    queryKey: ["conversations"],
    queryFn: fetchConversations,
    refetchInterval: 5000,
    // Agent console must stay current even when the tab is hidden.
    refetchIntervalInBackground: true,
  });

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold tracking-tight">{t("title")}</h1>

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

      {data !== undefined && data.length === 0 && (
        <div className="rounded-xl border border-dashed border-zinc-300 p-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
          {t("empty")}
        </div>
      )}

      {data !== undefined && data.length > 0 && (
        <ul className="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
          {data.map((conversation) => (
            <li key={conversation.conversation_id}>
              <Link
                href={`/conversations/${conversation.conversation_id}`}
                className="flex items-center justify-between gap-4 px-4 py-3 text-sm hover:bg-zinc-50 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:hover:bg-zinc-900"
              >
                <span className="min-w-0">
                  <span className="block truncate font-medium">
                    {conversation.visitor_name ?? t("anonymousVisitor")}
                  </span>
                  <span className="block truncate text-xs text-zinc-500">
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
          ))}
        </ul>
      )}
    </div>
  );
}
