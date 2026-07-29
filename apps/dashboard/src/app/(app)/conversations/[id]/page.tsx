"use client";

import { use, useEffect, useRef, useState } from "react";

import { subscribeToConversation } from "@/lib/gateway";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import {
  attachmentSchema,
  cannedResponseListSchema,
  chatMessageSchema,
  conversationSchema,
  messageListSchema,
  noteListSchema,
  noteSchema,
  userListSchema,
  type Attachment,
} from "@/lib/api/schemas";
import { flashTitle, playPing } from "@/lib/notify";
import { createTypingNotifier, type TypingNotifier } from "@/lib/typing";
import { btnSmall } from "@/lib/ui";

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
  replyToMessageId: string | null = null,
) {
  const response = await fetch(`/api/cp/conversations/${conversationId}/messages`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      idempotency_key: idempotencyKey,
      content_type: "text",
      body,
      ...(attachmentIds.length > 0 ? { attachment_ids: attachmentIds } : {}),
      ...(replyToMessageId !== null ? { reply_to_message_id: replyToMessageId } : {}),
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

async function fetchAgents() {
  const response = await fetch("/api/cp/users", { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed to load agents (${response.status})`);
  return userListSchema.parse(await response.json()).data;
}

async function transferConversation(conversationId: string, toAgentId: string, note: string) {
  const response = await fetch(`/api/cp/conversations/${conversationId}/transfer`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ to_agent_id: toAgentId, note: note.trim() === "" ? null : note.trim() }),
  });
  if (!response.ok) throw new Error(`Transfer failed (${response.status})`);
  return conversationSchema.parse(await response.json());
}

async function setStatus(conversationId: string, status: "open" | "closed") {
  const response = await fetch(`/api/cp/conversations/${conversationId}`, {
    method: "PATCH",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ status }),
  });
  if (!response.ok) throw new Error(`Status change failed (${response.status})`);
  return conversationSchema.parse(await response.json());
}

async function setPriority(conversationId: string, priority: string) {
  const response = await fetch(`/api/cp/conversations/${conversationId}`, {
    method: "PATCH",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ priority }),
  });
  if (!response.ok) throw new Error(`Priority change failed (${response.status})`);
  return conversationSchema.parse(await response.json());
}

async function setSpam(conversationId: string, isSpam: boolean) {
  const response = await fetch(`/api/cp/conversations/${conversationId}`, {
    method: "PATCH",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ is_spam: isSpam }),
  });
  if (!response.ok) throw new Error(`Spam change failed (${response.status})`);
  return conversationSchema.parse(await response.json());
}

async function fetchNotes(conversationId: string) {
  const response = await fetch(`/api/cp/conversations/${conversationId}/notes`, {
    cache: "no-store",
  });
  if (!response.ok) throw new Error(`Failed to load notes (${response.status})`);
  return noteListSchema.parse(await response.json()).data;
}

async function addNote(conversationId: string, body: string) {
  const response = await fetch(`/api/cp/conversations/${conversationId}/notes`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ body }),
  });
  if (!response.ok) throw new Error(`Add note failed (${response.status})`);
  return noteSchema.parse(await response.json());
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

/**
 * Render a message body. Flow "rich" messages ({text, options[]}) are shown
 * as text + non-interactive option chips instead of raw JSON (Phase 36).
 */
function renderBody(message: { content_type: string; body: string }) {
  if (message.content_type === "rich") {
    try {
      const parsed = JSON.parse(message.body) as { text?: unknown; options?: unknown };
      const text = typeof parsed.text === "string" ? parsed.text : "";
      const options = Array.isArray(parsed.options)
        ? parsed.options.filter((option): option is string => typeof option === "string")
        : [];
      return (
        <>
          {text}
          {options.length > 0 && (
            <div className="mt-1.5 flex flex-wrap gap-1">
              {options.map((option) => (
                <span
                  key={option}
                  className="rounded-full border border-black/15 bg-white/40 px-2 py-0.5 text-xs dark:border-white/15 dark:bg-black/20"
                >
                  {option}
                </span>
              ))}
            </div>
          )}
        </>
      );
    } catch {
      return message.body;
    }
  }
  return message.body;
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
  // Phase 30: ping the agent when a NEW inbound (visitor/contact) message
  // lands. -1 = first load, which must stay silent.
  const inboundCountRef = useRef(-1);

  const { data, isPending, isError } = useQuery({
    queryKey: ["conversation", id, "messages"],
    queryFn: () => fetchMessages(id),
    // Live pushes arrive over the gateway WebSocket; polling stays as the
    // slow safety net (RULES-failure-retry: realtime is best-effort).
    refetchInterval: 15000,
    refetchIntervalInBackground: true,
  });

  useEffect(() => {
    if (data === undefined) return;
    const inbound = data.data.filter(
      (message) => message.sender_type === "visitor" || message.sender_type === "contact",
    ).length;
    if (inboundCountRef.current >= 0 && inbound > inboundCountRef.current) {
      playPing();
      flashTitle("🔔");
    }
    inboundCountRef.current = inbound;
  }, [data]);

  // Phase 33: viewing the thread advances this agent's read cursor.
  const lastMarkedRef = useRef(-1);
  useEffect(() => {
    if (data === undefined || data.last_sequence === lastMarkedRef.current) return;
    lastMarkedRef.current = data.last_sequence;
    void fetch(`/api/cp/conversations/${id}/read`, { method: "POST" }).then(() => {
      void queryClient.invalidateQueries({ queryKey: ["conversations"] });
    });
  }, [data, id, queryClient]);

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

  const statusMutation = useMutation({
    mutationFn: (status: "open" | "closed") => setStatus(id, status),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["conversation", id, "meta"] });
      void queryClient.invalidateQueries({ queryKey: ["conversations"] });
    },
  });

  const priorityMutation = useMutation({
    mutationFn: (priority: string) => setPriority(id, priority),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["conversation", id, "meta"] });
      void queryClient.invalidateQueries({ queryKey: ["conversations"] });
    },
  });

  const spamMutation = useMutation({
    mutationFn: (isSpam: boolean) => setSpam(id, isSpam),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["conversation", id, "meta"] });
      void queryClient.invalidateQueries({ queryKey: ["conversations"] });
    },
  });

  const [transferOpen, setTransferOpen] = useState(false);
  const [transferTo, setTransferTo] = useState("");
  const [transferNote, setTransferNote] = useState("");

  const agents = useQuery({ queryKey: ["agents"], queryFn: fetchAgents, enabled: transferOpen });

  const transferMutation = useMutation({
    mutationFn: () => transferConversation(id, transferTo, transferNote),
    onSuccess: () => {
      setTransferOpen(false);
      setTransferTo("");
      setTransferNote("");
      void queryClient.invalidateQueries({ queryKey: ["conversation", id, "meta"] });
      void queryClient.invalidateQueries({ queryKey: ["conversation", id, "notes"] });
      void queryClient.invalidateQueries({ queryKey: ["conversations"] });
    },
  });

  const notes = useQuery({
    queryKey: ["conversation", id, "notes"],
    queryFn: () => fetchNotes(id),
  });

  // Canned responses for the "/" composer picker (Phase 25).
  const canned = useQuery({
    queryKey: ["canned-responses"],
    queryFn: async () => {
      const res = await fetch("/api/cp/canned-responses", { cache: "no-store" });
      if (!res.ok) throw new Error(`canned ${res.status}`);
      return cannedResponseListSchema.parse(await res.json()).data;
    },
    staleTime: 60_000,
  });

  // Conversation tags (Phase 25).
  const [tagDraft, setTagDraft] = useState("");
  const tagsMutation = useMutation({
    mutationFn: async (tags: string[]) => {
      const res = await fetch(`/api/cp/conversations/${id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ tags }),
      });
      if (!res.ok) throw new Error(`tags ${res.status}`);
      return conversationSchema.parse(await res.json());
    },
    onSuccess: () => {
      setTagDraft("");
      void queryClient.invalidateQueries({ queryKey: ["conversation", id, "meta"] });
    },
  });

  const [noteDraft, setNoteDraft] = useState("");
  const noteMutation = useMutation({
    mutationFn: (body: string) => addNote(id, body),
    onSuccess: () => {
      setNoteDraft("");
      void queryClient.invalidateQueries({ queryKey: ["conversation", id, "notes"] });
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

  // Quoted-reply draft + reaction toggling (Phase 28).
  const [replyTo, setReplyTo] = useState<{ message_id: string; body: string } | null>(null);

  const mutation = useMutation({
    mutationFn: ({ body, attachmentIds }: { body: string; attachmentIds: string[] }) =>
      sendReply(id, crypto.randomUUID(), body, attachmentIds, replyTo?.message_id ?? null),
    onSuccess: () => {
      setReplyTo(null);
      void queryClient.invalidateQueries({ queryKey: ["conversation", id, "messages"] });
      void queryClient.invalidateQueries({ queryKey: ["conversations"] });
    },
  });

  const reactMutation = useMutation({
    mutationFn: async ({ messageId, emoji }: { messageId: string; emoji: string }) => {
      const response = await fetch(`/api/cp/conversations/${id}/messages/${messageId}/reaction`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ emoji }),
      });
      if (!response.ok) throw new Error(`React failed (${response.status})`);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["conversation", id, "messages"] });
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

  // On-demand AI assist for the sidebar (conversation redesign). Fetched only
  // when the agent clicks "Suggest" — no automatic AI spend.
  const assist = useMutation({
    mutationFn: async (): Promise<{ suggested_reply: string | null; summary: string | null; sentiment: string }> => {
      const res = await fetch(`/api/cp/conversations/${id}/assist`, { method: "POST" });
      if (!res.ok) throw new Error(`Assist failed (${res.status})`);
      return res.json();
    },
  });

  const displayName = conversation?.contact_name ?? conversation?.visitor_name ?? t("sender_visitor");
  const isVip = (conversation?.tags ?? []).includes("vip");
  const fmtTime = (iso: string | null | undefined) =>
    iso == null ? "—" : new Date(iso).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
  const durationLabel = (() => {
    if (conversation?.created_at == null || conversation.updated_at == null) return "—";
    const start = new Date(conversation.created_at).getTime();
    const end = new Date(conversation.updated_at).getTime();
    const s = Math.max(0, Math.round((end - start) / 1000));
    if (s < 60) return `${s}s`;
    if (s < 3600) return `${Math.floor(s / 60)}m ${s % 60}s`;
    return `${Math.floor(s / 3600)}h ${Math.floor((s % 3600) / 60)}m`;
  })();
  const channelLabel =
    conversation?.channel_type == null
      ? "Live Chat"
      : conversation.channel_type.charAt(0).toUpperCase() + conversation.channel_type.slice(1);

  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
      <div className="flex h-[calc(100dvh-7rem)] min-w-0 flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <h1 className="text-2xl font-bold tracking-tight">Conversation</h1>
        {visitorOnline && (
          <span className="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/40 dark:text-green-300">
            <span aria-hidden className="size-1.5 rounded-full bg-green-500" />
            {t("visitorOnline")}
          </span>
        )}
        {conversation?.channel_type != null && (
          <span
            className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${
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
        {typeof conversation?.csat_rating === "number" && (
          <span
            className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-900/40 dark:text-amber-200"
            title={conversation.csat_comment ?? undefined}
          >
            <span aria-hidden>★</span>
            {t("csatBadge", { rating: conversation.csat_rating })}
          </span>
        )}
        {(conversation?.tags ?? []).map((tag) => (
          <span
            key={tag}
            className="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300"
          >
            {tag}
            <button
              type="button"
              aria-label={t("removeTag", { tag })}
              onClick={() =>
                tagsMutation.mutate((conversation?.tags ?? []).filter((existing) => existing !== tag))
              }
              className="opacity-60 hover:opacity-100"
            >
              ✕
            </button>
          </span>
        ))}
        <form
          className="inline-flex"
          onSubmit={(event) => {
            event.preventDefault();
            const tag = tagDraft.trim();
            if (tag === "" || tagsMutation.isPending) return;
            tagsMutation.mutate([...(conversation?.tags ?? []), tag]);
          }}
        >
          <input
            type="text"
            value={tagDraft}
            maxLength={30}
            onChange={(event) => setTagDraft(event.target.value)}
            placeholder={t("addTag")}
            aria-label={t("addTag")}
            className="w-24 rounded-full border border-dashed border-zinc-300 px-2 py-0.5 text-xs focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-950"
          />
        </form>
        <div className="ms-auto flex items-center gap-2 text-sm">
          <span className="text-zinc-500">
            {conversation?.assigned_agent_name
              ? t("assignedTo", { name: conversation.assigned_agent_name })
              : t("unassigned")}
          </span>
          <select
            value={conversation?.priority ?? "normal"}
            disabled={priorityMutation.isPending}
            onChange={(e) => priorityMutation.mutate(e.target.value)}
            aria-label="Priority"
            className="rounded-md border border-zinc-200 bg-white px-2 py-1 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
          >
            <option value="urgent">Urgent</option>
            <option value="high">High</option>
            <option value="normal">Normal</option>
            <option value="low">Low</option>
          </select>
          <button
            type="button"
            disabled={assignMutation.isPending}
            onClick={() => assignMutation.mutate("me")}
            className={btnSmall}
          >
            {t("assignToMe")}
          </button>
          <button
            type="button"
            onClick={() => setTransferOpen(true)}
            className={btnSmall}
          >
            Transfer
          </button>
          <button
            type="button"
            disabled={spamMutation.isPending}
            onClick={() => spamMutation.mutate(!(conversation?.is_spam ?? false))}
            className={btnSmall}
          >
            {conversation?.is_spam === true ? "Not spam" : "Mark spam"}
          </button>
          {conversation?.status === "closed" ? (
            <button
              type="button"
              disabled={statusMutation.isPending}
              onClick={() => statusMutation.mutate("open")}
              className={btnSmall}
            >
              {t("reopen")}
            </button>
          ) : (
            <button
              type="button"
              disabled={statusMutation.isPending}
              onClick={() => statusMutation.mutate("closed")}
              className={btnSmall}
            >
              {t("close")}
            </button>
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

      <div
        ref={logRef}
        role="log"
        aria-label={t("threadTitle")}
        aria-live="polite"
        className="flex-1 space-y-2 overflow-y-auto rounded-2xl border border-zinc-200 bg-white shadow-sm dark:bg-zinc-900 p-4 dark:border-zinc-800"
      >
        {data?.data.map((message) => (
          <div
            key={message.message_id}
            className={`group relative max-w-[70%] rounded-xl px-3 py-2 text-sm whitespace-pre-wrap ${
              message.sender_type === "agent"
                ? "ms-auto bg-indigo-600 text-white"
                : "bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100"
            }`}
          >
            {/* Hover actions (Phase 28): quick reactions + reply-with-quote */}
            <div className="absolute -top-3 end-2 hidden items-center gap-0.5 rounded-full border border-zinc-200 bg-white px-1.5 py-0.5 shadow-sm group-hover:inline-flex dark:border-zinc-700 dark:bg-zinc-900">
              {["👍", "❤️", "😂", "😮"].map((emoji) => (
                <button
                  key={emoji}
                  type="button"
                  aria-label={emoji}
                  onClick={() => reactMutation.mutate({ messageId: message.message_id, emoji })}
                  className="rounded-full px-1 text-sm hover:scale-125"
                >
                  {emoji}
                </button>
              ))}
              <button
                type="button"
                aria-label={t("replyWithQuote")}
                title={t("replyWithQuote")}
                onClick={() =>
                  setReplyTo({ message_id: message.message_id, body: message.body.slice(0, 140) })
                }
                className="rounded-full px-1 text-sm text-zinc-500 hover:scale-110 dark:text-zinc-400"
              >
                ↩
              </button>
            </div>
            <span className="mb-0.5 block text-[10px] uppercase tracking-wide opacity-70">
              {t(`sender_${message.sender_type}`)} · #{message.sequence_number}
            </span>
            {message.reply_to != null && (
              <div
                className={`mb-1.5 line-clamp-2 rounded-md border-s-2 px-2 py-1 text-xs ${
                  message.sender_type === "agent"
                    ? "border-white/60 bg-black/15"
                    : "border-indigo-500 bg-black/5 dark:bg-white/5"
                }`}
              >
                {message.reply_to.body}
              </div>
            )}
            {renderBody(message)}
            {(message.reactions?.length ?? 0) > 0 && (
              <div className="mt-1 flex gap-1">
                {message.reactions?.map((reaction) => (
                  <button
                    key={reaction.emoji}
                    type="button"
                    onClick={() =>
                      reactMutation.mutate({ messageId: message.message_id, emoji: reaction.emoji })
                    }
                    className="rounded-full border border-black/15 bg-white/40 px-1.5 text-xs dark:border-white/20 dark:bg-black/20"
                  >
                    {reaction.emoji}
                    {reaction.count > 1 ? ` ${reaction.count}` : ""}
                  </button>
                ))}
              </div>
            )}
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

      {/* Internal notes — private to agents, never sent to the visitor. */}
      <section className="rounded-xl border border-amber-300/60 bg-amber-50/60 p-3 dark:border-amber-500/25 dark:bg-amber-500/5">
        <h2 className="mb-2 flex items-center gap-1.5 text-xs font-semibold text-amber-800 dark:text-amber-300">
          <span aria-hidden>🔒</span>
          {t("internalNotes")}
        </h2>
        {notes.data !== undefined && notes.data.length > 0 && (
          <ul className="mb-2 max-h-28 space-y-1.5 overflow-y-auto">
            {notes.data.map((note) => (
              <li key={note.note_id} className="text-sm">
                <span className="font-medium text-amber-900 dark:text-amber-200">
                  {note.author_name ?? "Agent"}:
                </span>{" "}
                <span className="whitespace-pre-wrap text-zinc-700 dark:text-zinc-300">
                  {note.body}
                </span>
              </li>
            ))}
          </ul>
        )}
        <form
          onSubmit={(event) => {
            event.preventDefault();
            const body = noteDraft.trim();
            if (body.length > 0 && !noteMutation.isPending) noteMutation.mutate(body);
          }}
          className="flex gap-2"
        >
          <label htmlFor="note" className="sr-only">
            {t("internalNotes")}
          </label>
          <input
            id="note"
            value={noteDraft}
            onChange={(event) => setNoteDraft(event.target.value)}
            placeholder={t("notePlaceholder")}
            maxLength={8000}
            className="flex-1 rounded-md border border-amber-300/70 bg-white px-2.5 py-1.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-amber-500 dark:border-amber-500/30 dark:bg-zinc-900"
          />
          <button
            type="submit"
            disabled={noteMutation.isPending || noteDraft.trim().length === 0}
            className="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-amber-500 disabled:opacity-60"
          >
            {t("addNote")}
          </button>
        </form>
      </section>

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

      {/* "/" canned-response picker (Phase 25): draft starts with "/" ⇒ suggest. */}
      {draft.startsWith("/") && (canned.data?.length ?? 0) > 0 && (
        <div className="rounded-md border border-zinc-200 bg-white p-1 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
          {(canned.data ?? [])
            .filter(
              (item) =>
                draft === "/" ||
                item.shortcut.startsWith(draft.slice(1).toLowerCase()) ||
                item.title.toLowerCase().includes(draft.slice(1).toLowerCase()),
            )
            .slice(0, 5)
            .map((item) => (
              <button
                key={item.canned_response_id}
                type="button"
                onClick={() => setDraft(item.body)}
                className="flex w-full items-baseline gap-2 rounded px-2 py-1.5 text-start text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800"
              >
                <code className="text-xs text-indigo-600 dark:text-indigo-400">/{item.shortcut}</code>
                <span className="truncate text-zinc-600 dark:text-zinc-300">{item.body}</span>
              </button>
            ))}
        </div>
      )}

      {replyTo !== null && (
        <div className="flex items-center gap-2 rounded-md border-s-2 border-indigo-500 bg-zinc-100 px-3 py-1.5 text-xs dark:bg-zinc-800">
          <span className="min-w-0 flex-1 truncate">{replyTo.body}</span>
          <button type="button" aria-label={t("cancelReply")} onClick={() => setReplyTo(null)}>
            ✕
          </button>
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

      {/* Right sidebar: visitor, AI assist, conversation details */}
      <aside className="space-y-4">
        <section className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
          <div className="flex items-center justify-between gap-2">
            <span className="flex items-center gap-2 font-semibold">
              <span aria-hidden className={`size-2 rounded-full ${visitorOnline ? "bg-emerald-500" : "bg-zinc-300 dark:bg-zinc-600"}`} />
              {displayName}
            </span>
            <span className={`text-xs font-medium ${visitorOnline ? "text-emerald-600" : "text-zinc-400"}`}>
              {visitorOnline ? "Online" : "Offline"}
            </span>
          </div>
          {(isVip || conversation?.channel_type != null) && (
            <div className="mt-3 flex flex-wrap gap-1.5">
              {isVip && (
                <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">
                  VIP Visitor
                </span>
              )}
              {conversation?.channel_type != null && (
                <span className="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                  {channelLabel}
                </span>
              )}
            </div>
          )}
          {conversation?.visitor_location != null && conversation.visitor_location !== "" && (
            <p className="mt-2 flex items-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-300">
              <span aria-hidden>📍</span>
              {conversation.visitor_location}
            </p>
          )}
          <dl className="mt-3 space-y-1.5 text-xs">
            <div className="flex justify-between gap-2">
              <dt className="text-zinc-500">First seen</dt>
              <dd className="truncate text-zinc-700 dark:text-zinc-300">{fmtTime(conversation?.created_at)}</dd>
            </div>
            {conversation?.source_url != null && conversation.source_url !== "" && (
              <div className="flex justify-between gap-2">
                <dt className="shrink-0 text-zinc-500">Current page</dt>
                <dd className="truncate text-zinc-700 dark:text-zinc-300">{conversation.source_url}</dd>
              </div>
            )}
            {conversation?.contact_email != null && (
              <div className="flex justify-between gap-2">
                <dt className="shrink-0 text-zinc-500">Email</dt>
                <dd className="truncate text-zinc-700 dark:text-zinc-300">{conversation.contact_email}</dd>
              </div>
            )}
          </dl>
        </section>

        <section className="rounded-2xl border border-indigo-200 bg-gradient-to-br from-indigo-50 to-fuchsia-50 p-4 shadow-sm dark:border-indigo-500/20 dark:from-indigo-500/10 dark:to-fuchsia-500/10">
          <div className="mb-3 flex items-center gap-2">
            <span className="grid size-7 place-items-center rounded-lg bg-indigo-600 text-sm text-white" aria-hidden>✦</span>
            <h2 className="text-sm font-semibold">AI Assistant</h2>
          </div>
          <button
            type="button"
            onClick={() => assist.mutate()}
            disabled={assist.isPending}
            className="w-full rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:opacity-60"
          >
            {assist.isPending ? "Thinking…" : "Suggest a reply"}
          </button>
          {assist.isError && <p className="mt-2 text-xs text-red-600" role="alert">Couldn’t generate a suggestion.</p>}
          {assist.data != null && (
            <div className="mt-3 space-y-3">
              {assist.data.suggested_reply != null && (
                <div>
                  <p className="mb-1 text-xs font-medium text-zinc-500">Suggested reply</p>
                  <div className="rounded-lg bg-white/70 p-2.5 text-sm text-zinc-700 dark:bg-white/5 dark:text-zinc-200">
                    {assist.data.suggested_reply}
                  </div>
                  <button
                    type="button"
                    onClick={() => setDraft(assist.data?.suggested_reply ?? "")}
                    className="mt-1.5 rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-indigo-500"
                  >
                    Insert
                  </button>
                </div>
              )}
              {assist.data.summary != null && (
                <div>
                  <p className="mb-1 text-xs font-medium text-zinc-500">Conversation summary</p>
                  <p className="text-sm text-zinc-600 dark:text-zinc-300">{assist.data.summary}</p>
                </div>
              )}
              <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-zinc-500">Sentiment</span>
                <span
                  className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                    assist.data.sentiment === "positive"
                      ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                      : assist.data.sentiment === "negative"
                        ? "bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300"
                        : "bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                  }`}
                >
                  {assist.data.sentiment.charAt(0).toUpperCase() + assist.data.sentiment.slice(1)}
                </span>
              </div>
            </div>
          )}
        </section>

        <section className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
          <h2 className="mb-3 text-sm font-semibold">Conversation details</h2>
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between gap-2">
              <dt className="text-zinc-500">Channel</dt>
              <dd className="text-zinc-700 dark:text-zinc-300">{channelLabel}</dd>
            </div>
            <div className="flex justify-between gap-2">
              <dt className="text-zinc-500">Duration</dt>
              <dd className="tabular-nums text-zinc-700 dark:text-zinc-300">{durationLabel}</dd>
            </div>
            <div className="flex justify-between gap-2">
              <dt className="text-zinc-500">Messages</dt>
              <dd className="tabular-nums text-zinc-700 dark:text-zinc-300">{data?.data.length ?? 0}</dd>
            </div>
            <div className="flex justify-between gap-2">
              <dt className="text-zinc-500">Status</dt>
              <dd className="capitalize text-zinc-700 dark:text-zinc-300">{conversation?.status ?? "—"}</dd>
            </div>
          </dl>
        </section>
      </aside>

      {transferOpen && (
        <div className="fixed inset-0 z-40 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="Transfer conversation">
          <button type="button" aria-label="Close" className="absolute inset-0 bg-black/30" onClick={() => setTransferOpen(false)} />
          <div className="relative w-full max-w-sm space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
            <div>
              <h2 className="text-lg font-bold">Transfer to a colleague</h2>
              <p className="text-sm text-zinc-500">Reassign this conversation and leave a handoff note.</p>
            </div>
            <div className="space-y-1">
              <label htmlFor="transfer-agent" className="block text-sm font-medium">Agent</label>
              <select
                id="transfer-agent"
                value={transferTo}
                onChange={(e) => setTransferTo(e.target.value)}
                className="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950"
              >
                <option value="">{agents.isPending ? "Loading agents…" : "Select an agent…"}</option>
                {(agents.data ?? []).map((a) => (
                  <option key={a.user_id} value={a.user_id}>{a.name}</option>
                ))}
              </select>
            </div>
            <div className="space-y-1">
              <label htmlFor="transfer-note" className="block text-sm font-medium">Handoff note</label>
              <textarea
                id="transfer-note"
                rows={3}
                maxLength={2000}
                value={transferNote}
                onChange={(e) => setTransferNote(e.target.value)}
                placeholder="Why are you handing this off? (optional)"
                className="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950"
              />
            </div>
            {transferMutation.isError && (
              <p className="text-sm text-red-600" role="alert">
                Couldn’t transfer — the agent must be an active member of this conversation’s department.
              </p>
            )}
            <div className="flex gap-2">
              <button
                type="button"
                disabled={transferMutation.isPending || transferTo === ""}
                onClick={() => transferMutation.mutate()}
                className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60"
              >
                {transferMutation.isPending ? "Transferring…" : "Transfer"}
              </button>
              <button type="button" onClick={() => setTransferOpen(false)} className="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700">
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
