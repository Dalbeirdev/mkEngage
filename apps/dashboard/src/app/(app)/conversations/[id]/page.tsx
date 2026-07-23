"use client";

import { use, useEffect, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { chatMessageSchema, messageListSchema } from "@/lib/api/schemas";

async function fetchMessages(conversationId: string) {
  const response = await fetch(`/api/cp/conversations/${conversationId}/messages`, {
    cache: "no-store",
  });
  if (!response.ok) throw new Error(`Failed to load messages (${response.status})`);
  return messageListSchema.parse(await response.json());
}

async function sendReply(conversationId: string, idempotencyKey: string, body: string) {
  const response = await fetch(`/api/cp/conversations/${conversationId}/messages`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      idempotency_key: idempotencyKey,
      content_type: "text",
      body,
    }),
  });
  if (!response.ok) throw new Error(`Send failed (${response.status})`);
  return chatMessageSchema.parse(await response.json());
}

/** Agent thread view: sequence-ordered history + reply box (polling, 3 s). */
export default function ConversationThreadPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = use(params);
  const t = useTranslations("conversations");
  const queryClient = useQueryClient();
  const [draft, setDraft] = useState("");
  const logRef = useRef<HTMLDivElement>(null);

  const { data, isPending, isError } = useQuery({
    queryKey: ["conversation", id, "messages"],
    queryFn: () => fetchMessages(id),
    refetchInterval: 3000,
    // Agent console must stay current even when the tab is hidden.
    refetchIntervalInBackground: true,
  });

  const mutation = useMutation({
    mutationFn: (body: string) => sendReply(id, crypto.randomUUID(), body),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["conversation", id, "messages"] });
      void queryClient.invalidateQueries({ queryKey: ["conversations"] });
    },
  });

  const count = data?.data.length ?? 0;
  useEffect(() => {
    const log = logRef.current;
    if (log !== null) log.scrollTop = log.scrollHeight;
  }, [count]);

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    const body = draft.trim();
    if (body.length === 0 || mutation.isPending) return;
    setDraft("");
    mutation.mutate(body);
  };

  return (
    <div className="flex h-[calc(100dvh-7rem)] flex-col space-y-4">
      <h1 className="text-2xl font-bold tracking-tight">{t("threadTitle")}</h1>

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

      <div
        ref={logRef}
        role="log"
        aria-label={t("threadTitle")}
        aria-live="polite"
        className="flex-1 space-y-2 overflow-y-auto rounded-xl border border-zinc-200 p-4 dark:border-zinc-800"
      >
        {data?.data.map((message) => (
          <div
            key={message.message_id}
            className={`max-w-[70%] rounded-xl px-3 py-2 text-sm whitespace-pre-wrap ${
              message.sender_type === "agent"
                ? "ms-auto bg-indigo-600 text-white"
                : "bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100"
            }`}
          >
            <span className="mb-0.5 block text-[10px] uppercase tracking-wide opacity-70">
              {t(`sender_${message.sender_type}`)} · #{message.sequence_number}
            </span>
            {message.body}
          </div>
        ))}
      </div>

      <form onSubmit={submit} className="flex gap-2">
        <label htmlFor="reply" className="sr-only">
          {t("replyLabel")}
        </label>
        <textarea
          id="reply"
          rows={1}
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Enter" && !event.shiftKey) {
              event.preventDefault();
              submit(event);
            }
          }}
          placeholder={t("replyPlaceholder")}
          className="flex-1 resize-none rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
        />
        <button
          type="submit"
          disabled={mutation.isPending}
          className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-60"
        >
          {mutation.isPending ? t("sending") : t("send")}
        </button>
      </form>
      {mutation.isError && (
        <p className="text-sm text-red-600" role="alert">
          {t("sendError")}
        </p>
      )}
    </div>
  );
}
