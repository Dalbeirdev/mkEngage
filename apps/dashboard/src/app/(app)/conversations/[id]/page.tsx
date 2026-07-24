"use client";

import { use, useEffect, useRef, useState } from "react";

import { subscribeToConversation } from "@/lib/gateway";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import {
  attachmentSchema,
  chatMessageSchema,
  conversationSchema,
  messageListSchema,
  type Attachment,
} from "@/lib/api/schemas";
import { createTypingNotifier, type TypingNotifier } from "@/lib/typing";

async function fetchMessages(conversationId: string) {
  const response = await fetch(`/api/cp/conversations/${conversationId}/messages`, {
    cache: "no-store",
  });
  if (!response.ok) throw new Error(`Failed to load messages (${response.status})`);
  return messageListSchema.parse(await response.json());
}

async function sendReply(
  conversationId: string,
  idempotencyKey: string,
  body: string,
  attachmentIds: string[],
) {
  const response = await fetch(`/api/cp/conversations/${conversationId}/messages`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      idempotency_key: idempotencyKey,
      content_type: "text",
      body,
      ...(attachmentIds.length > 0 ? { attachment_ids: attachmentIds } : {}),
    }),
  });
  if (!response.ok) throw new Error(`Send failed (${response.status})`);
  return chatMessageSchema.parse(await response.json());
}

async function uploadAttachment(conversationId: string, file: File): Promise<Attachment> {
  const form = new FormData();
  form.append("file", file, file.name);

  const response = await fetch(`/api/cp/conversations/${conversationId}/attachments`, {
    method: "POST",
    body: form,
  });
  if (!response.ok) throw new Error(`Upload failed (${response.status})`);
  return attachmentSchema.parse(await response.json());
}

async function assignConversation(conversationId: string, assignee: "me" | "auto") {
  const response = await fetch(`/api/cp/conversations/${conversationId}/assign`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ assignee }),
  });
  if (!response.ok) throw new Error(`Assign failed (${response.status})`);
  return conversationSchema.parse(await response.json());
}

async function openAttachment(conversationId: string, attachment: Attachment): Promise<void> {
  if (attachment.scan_status !== "clean") return;

  const response = await fetch(
    `/api/cp/conversations/${conversationId}/attachments/${attachment.attachment_id}/download`,
    { cache: "no-store" },
  );
  if (!response.ok) return;

  const { url } = (await response.json()) as { url: string };
  window.open(url, "_blank", "noopener");
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
  const [visitorTyping, setVisitorTyping] = useState(false);
  const [visitorOnline, setVisitorOnline] = useState(false);
  const [pendingAttachment, setPendingAttachment] = useState<Attachment | null>(null);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const logRef = useRef<HTMLDivElement>(null);
  const typingNotifierRef = useRef<TypingNotifier | null>(null);
  const typingClearRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const { data, isPending, isError } = useQuery({
    queryKey: ["conversation", id, "messages"],
    queryFn: () => fetchMessages(id),
    // Live pushes arrive over the gateway WebSocket; polling stays as the
    // slow safety net (RULES-failure-retry: realtime is best-effort).
    refetchInterval: 15000,
    refetchIntervalInBackground: true,
  });

  const { data: conversation } = useQuery({
    queryKey: ["conversation", id, "meta"],
    queryFn: async () => {
      const res = await fetch(`/api/cp/conversations/${id}`, { cache: "no-store" });
      if (!res.ok) throw new Error(`meta ${res.status}`);
      return conversationSchema.parse(await res.json());
    },
  });

  const assignMutation = useMutation({
    mutationFn: (assignee: "me" | "auto") => assignConversation(id, assignee),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["conversation", id, "meta"] });
      void queryClient.invalidateQueries({ queryKey: ["conversations"] });
    },
  });

  // Gateway subscription: instant refresh on message:new, plus ephemeral
  // typing + presence. Failure is fine — polling covers delivery; the effect
  // retries on conversation change only.
  useEffect(() => {
    const subscription = subscribeToConversation(id, {
      onMessage: () => {
        setVisitorTyping(false);
        void queryClient.invalidateQueries({ queryKey: ["conversation", id, "messages"] });
        void queryClient.invalidateQueries({ queryKey: ["conversations"] });
      },
      onTyping: (event) => {
        if (event.sender_type === "agent") return;
        if (typingClearRef.current !== null) clearTimeout(typingClearRef.current);
        setVisitorTyping(event.is_typing);
        if (event.is_typing) {
          // Safety: a lost "stopped" frame self-heals after 6 s.
          typingClearRef.current = setTimeout(() => setVisitorTyping(false), 6000);
        }
      },
      onPresence: (subs) => {
        setVisitorOnline(subs.some((sub) => sub.startsWith("visitor:")));
      },
    });

    // Throttled outgoing typing signal, bound to this subscription's lifetime.
    const notifier = createTypingNotifier((isTyping) => subscription.sendTyping(isTyping));
    typingNotifierRef.current = notifier;

    return () => {
      notifier.stop();
      typingNotifierRef.current = null;
      if (typingClearRef.current !== null) clearTimeout(typingClearRef.current);
      subscription.close();
      setVisitorTyping(false);
      setVisitorOnline(false);
    };
  }, [id, queryClient]);

  const mutation = useMutation({
    mutationFn: ({ body, attachmentIds }: { body: string; attachmentIds: string[] }) =>
      sendReply(id, crypto.randomUUID(), body, attachmentIds),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["conversation", id, "messages"] });
      void queryClient.invalidateQueries({ queryKey: ["conversations"] });
    },
  });

  const onFilePicked = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (file === undefined || uploading) return;

    setUploading(true);
    setUploadError(false);
    try {
      setPendingAttachment(await uploadAttachment(id, file));
    } catch {
      setUploadError(true);
    } finally {
      setUploading(false);
    }
  };

  const count = data?.data.length ?? 0;
  useEffect(() => {
    const log = logRef.current;
    if (log !== null) log.scrollTop = log.scrollHeight;
  }, [count]);

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    const body = draft.trim() || pendingAttachment?.file_name.trim() || "";
    if (body.length === 0 || mutation.isPending) return;
    setDraft("");
    typingNotifierRef.current?.stop();
    const attachmentIds = pendingAttachment === null ? [] : [pendingAttachment.attachment_id];
    setPendingAttachment(null);
    mutation.mutate({ body, attachmentIds });
  };

  return (
    <div className="flex h-[calc(100dvh-7rem)] flex-col space-y-4">
      <div className="flex items-center gap-3">
        <h1 className="text-2xl font-bold tracking-tight">{t("threadTitle")}</h1>
        {visitorOnline && (
          <span className="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/40 dark:text-green-300">
            <span aria-hidden className="size-1.5 rounded-full bg-green-500" />
            {t("visitorOnline")}
          </span>
        )}
        <div className="ms-auto flex items-center gap-2 text-sm">
          <span className="text-zinc-500">
            {conversation?.assigned_agent_name
              ? t("assignedTo", { name: conversation.assigned_agent_name })
              : t("unassigned")}
          </span>
          <button
            type="button"
            disabled={assignMutation.isPending}
            onClick={() => assignMutation.mutate("me")}
            className="rounded-md border border-zinc-300 px-2.5 py-1 text-xs font-medium hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:hover:bg-zinc-800"
          >
            {t("assignToMe")}
          </button>
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
            {message.attachments.map((attachment) => (
              <button
                key={attachment.attachment_id}
                type="button"
                disabled={attachment.scan_status !== "clean"}
                onClick={() => void openAttachment(id, attachment)}
                className="mt-1.5 flex max-w-full items-center gap-1.5 truncate rounded-lg border border-black/15 bg-white/30 px-2 py-1 text-xs disabled:opacity-70 dark:border-white/15 dark:bg-black/20"
              >
                📄 {attachment.file_name}
                <span className="opacity-70">
                  {attachment.scan_status === "clean"
                    ? `${Math.max(1, Math.round(attachment.size_bytes / 1024))} KB`
                    : t(
                        attachment.scan_status === "pending"
                          ? "attachmentScanning"
                          : "attachmentBlocked",
                      )}
                </span>
              </button>
            ))}
          </div>
        ))}
      </div>

      <div aria-hidden className="h-5 text-xs text-zinc-500 italic">
        {visitorTyping ? t("visitorTyping") : null}
      </div>

      {(pendingAttachment !== null || uploading || uploadError) && (
        <div className="text-xs">
          {uploading ? (
            <span className="text-zinc-500">{t("uploading")}</span>
          ) : uploadError ? (
            <span className="text-red-600" role="alert">
              {t("uploadError")}
            </span>
          ) : (
            <span className="inline-flex items-center gap-1.5 rounded-full border border-zinc-300 bg-zinc-100 px-2.5 py-1 text-zinc-800 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
              📎 {pendingAttachment?.file_name}
              <button
                type="button"
                aria-label={t("attachmentRemove")}
                onClick={() => setPendingAttachment(null)}
                className="text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
              >
                ✕
              </button>
            </span>
          )}
        </div>
      )}

      <form onSubmit={submit} className="flex gap-2">
        <input
          ref={fileInputRef}
          type="file"
          className="hidden"
          aria-hidden
          tabIndex={-1}
          onChange={(event) => void onFilePicked(event)}
        />
        <button
          type="button"
          aria-label={t("attachLabel")}
          disabled={uploading}
          onClick={() => fileInputRef.current?.click()}
          className="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
        >
          📎
        </button>
        <label htmlFor="reply" className="sr-only">
          {t("replyLabel")}
        </label>
        <textarea
          id="reply"
          rows={1}
          value={draft}
          onChange={(event) => {
            setDraft(event.target.value);
            typingNotifierRef.current?.input();
          }}
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
