"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { conversationSchema, liveVisitorsSchema, type LiveVisitor } from "@/lib/api/schemas";
import { btnSmall, cardPad, emptyState, pageTitle } from "@/lib/ui";

async function fetchLiveVisitors() {
  const response = await fetch("/api/cp/visitors/live", { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed to load visitors (${response.status})`);
  return liveVisitorsSchema.parse(await response.json()).data;
}

async function startConversation(payload: { visitor_id: string; message: string }) {
  const response = await fetch("/api/cp/conversations", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  if (!response.ok) throw new Error(`Start failed (${response.status})`);
  return conversationSchema.parse(await response.json());
}

function timeOnSite(firstSeen: string | null): string {
  if (firstSeen === null) return "—";
  const seconds = Math.max(0, Math.round((Date.now() - new Date(firstSeen).getTime()) / 1000));
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
  return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
}

function VisitorRow({ visitor }: { visitor: LiveVisitor }) {
  const t = useTranslations("visitors");
  const router = useRouter();
  const [message, setMessage] = useState("");
  const [composerOpen, setComposerOpen] = useState(false);

  const start = useMutation({
    mutationFn: startConversation,
    onSuccess: (conversation) => {
      router.push(`/conversations/${conversation.conversation_id}`);
    },
  });

  const name =
    visitor.contact_name ?? visitor.display_name ?? t("anonymous");

  const bucketClass = {
    hot: "bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300",
    warm: "bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200",
    cold: "bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400",
  }[visitor.lead_bucket];

  return (
    <li className="flex flex-col gap-2 border-b border-zinc-100 py-3 last:border-b-0 dark:border-zinc-800">
      <div className="flex items-center gap-3">
        <span
          className="grid size-9 shrink-0 place-items-center rounded-full bg-green-100 text-sm font-semibold text-green-800 dark:bg-green-900/40 dark:text-green-300"
          aria-hidden
        >
          {name.slice(0, 1).toUpperCase()}
        </span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-medium">
            {name}
            {visitor.contact_email !== null && (
              <span className="ms-2 text-xs font-normal text-zinc-500">{visitor.contact_email}</span>
            )}
          </p>
          <p className="truncate text-xs text-zinc-500">
            {visitor.page_title ?? visitor.current_url ?? t("pageUnknown")}
            <span className="ms-2">· {t("onSiteFor", { duration: timeOnSite(visitor.first_seen_at) })}</span>
          </p>
        </div>
        <span
          className={`inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${bucketClass}`}
          title={t("leadScoreTitle", { score: visitor.lead_score })}
        >
          {visitor.lead_bucket === "hot" ? "🔥" : visitor.lead_bucket === "warm" ? "☀️" : "❄️"}
          {visitor.lead_score}
        </span>
        {visitor.conversation_id !== null ? (
          <button
            type="button"
            onClick={() => router.push(`/conversations/${visitor.conversation_id}`)}
            className={btnSmall}
          >
            {t("openChat")}
          </button>
        ) : (
          <button type="button" onClick={() => setComposerOpen((value) => !value)} className={btnSmall}>
            {t("startChat")}
          </button>
        )}
      </div>

      {composerOpen && visitor.conversation_id === null && (
        <form
          className="flex items-center gap-2 ps-12"
          onSubmit={(event) => {
            event.preventDefault();
            if (message.trim() === "" || start.isPending) return;
            start.mutate({ visitor_id: visitor.visitor_id, message: message.trim() });
          }}
        >
          <input
            type="text"
            value={message}
            onChange={(event) => setMessage(event.target.value)}
            placeholder={t("messagePlaceholder")}
            className="min-w-0 flex-1 rounded-md border border-zinc-300 px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-950"
          />
          <button
            type="submit"
            disabled={start.isPending || message.trim() === ""}
            className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500 disabled:opacity-60"
          >
            {start.isPending ? t("sending") : t("send")}
          </button>
          {start.isError && (
            <span className="text-xs text-red-600" role="alert">
              {t("startError")}
            </span>
          )}
        </form>
      )}
    </li>
  );
}

export default function VisitorsPage() {
  const t = useTranslations("visitors");

  const { data, isPending, isError } = useQuery({
    queryKey: ["live-visitors"],
    queryFn: fetchLiveVisitors,
    refetchInterval: 5000,
    refetchIntervalInBackground: true,
  });

  return (
    <div className="max-w-3xl space-y-6">
      <div className="flex items-center gap-3">
        <h1 className={pageTitle}>{t("title")}</h1>
        {data !== undefined && (
          <span className="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/40 dark:text-green-300">
            <span aria-hidden className="size-1.5 animate-pulse rounded-full bg-green-500" />
            {t("onlineCount", { count: data.length })}
          </span>
        )}
      </div>
      <p className="text-sm text-zinc-500">{t("subtitle")}</p>

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

      {data !== undefined && data.length === 0 && <p className={emptyState}>{t("empty")}</p>}

      {data !== undefined && data.length > 0 && (
        <div className={cardPad}>
          <ul>
            {data.map((visitor) => (
              <VisitorRow key={visitor.visitor_id} visitor={visitor} />
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
