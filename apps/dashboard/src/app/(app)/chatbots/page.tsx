"use client";

import { useState } from "react";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { chatbotListSchema, chatbotSchema } from "@/lib/api/schemas";

async function fetchChatbots() {
  const response = await fetch("/api/cp/chatbots", { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed to load chatbots (${response.status})`);
  return chatbotListSchema.parse(await response.json()).data;
}

async function createChatbot(name: string) {
  const response = await fetch("/api/cp/chatbots", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ name }),
  });
  if (!response.ok) throw new Error(`Create failed (${response.status})`);
  return chatbotSchema.parse(await response.json());
}

const statusStyles: Record<string, string> = {
  active: "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300",
  draft: "bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300",
  paused: "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300",
};

export default function ChatbotsPage() {
  const t = useTranslations("chatbots");
  const queryClient = useQueryClient();
  const [name, setName] = useState("");

  const { data, isPending, isError } = useQuery({
    queryKey: ["chatbots"],
    queryFn: fetchChatbots,
  });

  const create = useMutation({
    mutationFn: createChatbot,
    onSuccess: () => {
      setName("");
      void queryClient.invalidateQueries({ queryKey: ["chatbots"] });
    },
  });

  return (
    <div className="max-w-2xl space-y-6">
      <h1 className="text-2xl font-bold tracking-tight">{t("title")}</h1>

      <form
        onSubmit={(event) => {
          event.preventDefault();
          if (name.trim().length > 0 && !create.isPending) create.mutate(name.trim());
        }}
        className="flex items-end gap-2"
        aria-label={t("createTitle")}
      >
        <div className="flex-1 space-y-1">
          <label htmlFor="bot-name" className="block text-sm font-medium">
            {t("nameLabel")}
          </label>
          <input
            id="bot-name"
            type="text"
            value={name}
            maxLength={100}
            onChange={(event) => setName(event.target.value)}
            className="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
          />
        </div>
        <button
          type="submit"
          disabled={create.isPending || name.trim().length === 0}
          className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-60"
        >
          {create.isPending ? t("creating") : t("create")}
        </button>
      </form>

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
        <ul className="divide-y divide-zinc-200 rounded-2xl border border-zinc-200 bg-white shadow-sm dark:bg-zinc-900 dark:divide-zinc-800 dark:border-zinc-800">
          {data.map((chatbot) => (
            <li key={chatbot.chatbot_id}>
              <Link
                href={`/chatbots/${chatbot.chatbot_id}`}
                className="flex items-center justify-between gap-4 px-4 py-3 text-sm hover:bg-zinc-50 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:hover:bg-zinc-900"
              >
                <span className="min-w-0">
                  <span className="block truncate font-medium">{chatbot.name}</span>
                  <span className="block truncate text-xs text-zinc-500">
                    {chatbot.provider}
                    {chatbot.model !== null ? ` · ${chatbot.model}` : ""}
                  </span>
                </span>
                <span
                  className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${statusStyles[chatbot.status]}`}
                >
                  {t(`status_${chatbot.status}`)}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
