"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { z } from "zod";

import { btnPrimary, btnSmall, cardPad, emptyState, input, pageTitle } from "@/lib/ui";

const keyListSchema = z.object({
  data: z.array(
    z.object({
      api_key_id: z.uuid(),
      name: z.string(),
      prefix: z.string(),
      last_used_at: z.string().nullable(),
      revoked_at: z.string().nullable(),
      created_at: z.string().nullable(),
    }),
  ),
});

const webhookListSchema = z.object({
  data: z.array(
    z.object({
      webhook_endpoint_id: z.uuid(),
      url: z.string(),
      events: z.array(z.string()),
      status: z.string(),
      created_at: z.string().nullable(),
    }),
  ),
});

const EVENTS = [
  "message.created",
  "conversation.created",
  "conversation.assigned",
  "conversation.closed",
  "csat.received",
] as const;

function Reveal({ label, value }: { label: string; value: string }) {
  const [copied, setCopied] = useState(false);
  return (
    <div
      role="alert"
      className="space-y-1 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950"
    >
      <p className="font-semibold text-amber-900 dark:text-amber-200">{label}</p>
      <div className="flex items-center gap-2">
        <code className="min-w-0 flex-1 break-all rounded bg-white px-2 py-1 text-xs dark:bg-zinc-900">{value}</code>
        <button
          type="button"
          onClick={() => {
            void navigator.clipboard.writeText(value).then(() => {
              setCopied(true);
              setTimeout(() => setCopied(false), 2000);
            });
          }}
          className={btnSmall}
        >
          {copied ? "✓" : "Copy"}
        </button>
      </div>
    </div>
  );
}

export default function DevelopersPage() {
  const t = useTranslations("developers");
  const queryClient = useQueryClient();

  const [keyName, setKeyName] = useState("");
  const [newKey, setNewKey] = useState<string | null>(null);
  const [webhookUrl, setWebhookUrl] = useState("");
  const [webhookEvents, setWebhookEvents] = useState<string[]>(["message.created"]);
  const [newSecret, setNewSecret] = useState<string | null>(null);

  const keys = useQuery({
    queryKey: ["api-keys"],
    queryFn: async () => {
      const res = await fetch("/api/cp/api-keys", { cache: "no-store" });
      if (!res.ok) throw new Error(`keys ${res.status}`);
      return keyListSchema.parse(await res.json()).data;
    },
  });

  const webhooks = useQuery({
    queryKey: ["webhook-endpoints"],
    queryFn: async () => {
      const res = await fetch("/api/cp/webhook-endpoints", { cache: "no-store" });
      if (!res.ok) throw new Error(`webhooks ${res.status}`);
      return webhookListSchema.parse(await res.json()).data;
    },
  });

  const createKey = useMutation({
    mutationFn: async () => {
      const res = await fetch("/api/cp/api-keys", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name: keyName.trim() }),
      });
      if (!res.ok) throw new Error(`create ${res.status}`);
      return (await res.json()) as { key: string };
    },
    onSuccess: (result) => {
      setNewKey(result.key);
      setKeyName("");
      void queryClient.invalidateQueries({ queryKey: ["api-keys"] });
    },
  });

  const revokeKey = useMutation({
    mutationFn: async (id: string) => {
      const res = await fetch(`/api/cp/api-keys/${id}`, { method: "DELETE" });
      if (!res.ok) throw new Error(`revoke ${res.status}`);
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["api-keys"] }),
  });

  const createWebhook = useMutation({
    mutationFn: async () => {
      const res = await fetch("/api/cp/webhook-endpoints", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ url: webhookUrl.trim(), events: webhookEvents }),
      });
      if (!res.ok) throw new Error(`create ${res.status}`);
      return (await res.json()) as { secret: string };
    },
    onSuccess: (result) => {
      setNewSecret(result.secret);
      setWebhookUrl("");
      void queryClient.invalidateQueries({ queryKey: ["webhook-endpoints"] });
    },
  });

  const deleteWebhook = useMutation({
    mutationFn: async (id: string) => {
      const res = await fetch(`/api/cp/webhook-endpoints/${id}`, { method: "DELETE" });
      if (!res.ok) throw new Error(`delete ${res.status}`);
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["webhook-endpoints"] }),
  });

  const testWebhook = useMutation({
    mutationFn: async (id: string) => {
      const res = await fetch(`/api/cp/webhook-endpoints/${id}/test`, { method: "POST" });
      if (!res.ok) throw new Error(`test ${res.status}`);
    },
  });

  return (
    <div className="max-w-2xl space-y-6">
      <h1 className={pageTitle}>{t("title")}</h1>
      <p className="text-sm text-zinc-500">{t("subtitle")}</p>

      {/* API keys */}
      <section className={`${cardPad} space-y-3`}>
        <h2 className="font-semibold">{t("keysTitle")}</h2>
        <p className="text-xs text-zinc-500">{t("keysHelp")}</p>
        <form
          className="flex gap-2"
          onSubmit={(event) => {
            event.preventDefault();
            if (!createKey.isPending && keyName.trim() !== "") createKey.mutate();
          }}
        >
          <input
            type="text"
            required
            maxLength={100}
            value={keyName}
            onChange={(event) => setKeyName(event.target.value)}
            placeholder={t("keyNamePlaceholder")}
            aria-label={t("keyNamePlaceholder")}
            className={`${input} flex-1`}
          />
          <button type="submit" disabled={createKey.isPending} className={btnPrimary}>
            {t("createKey")}
          </button>
        </form>
        {newKey !== null && <Reveal label={t("keyRevealed")} value={newKey} />}
        {(keys.data ?? []).length === 0 && <p className={emptyState}>{t("noKeys")}</p>}
        <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
          {(keys.data ?? []).map((key) => (
            <li key={key.api_key_id} className="flex items-center gap-3 py-2 text-sm">
              <code className="rounded bg-zinc-100 px-1.5 py-0.5 text-xs dark:bg-zinc-800">{key.prefix}</code>
              <span className="min-w-0 flex-1 truncate">{key.name}</span>
              {key.revoked_at !== null ? (
                <span className="text-xs text-red-600">{t("revoked")}</span>
              ) : (
                <button type="button" onClick={() => revokeKey.mutate(key.api_key_id)} className={btnSmall}>
                  {t("revoke")}
                </button>
              )}
            </li>
          ))}
        </ul>
      </section>

      {/* Webhooks */}
      <section className={`${cardPad} space-y-3`}>
        <h2 className="font-semibold">{t("webhooksTitle")}</h2>
        <p className="text-xs text-zinc-500">{t("webhooksHelp")}</p>
        <form
          className="space-y-2"
          onSubmit={(event) => {
            event.preventDefault();
            if (!createWebhook.isPending && webhookUrl.trim() !== "" && webhookEvents.length > 0) {
              createWebhook.mutate();
            }
          }}
        >
          <input
            type="url"
            required
            maxLength={2048}
            value={webhookUrl}
            onChange={(event) => setWebhookUrl(event.target.value)}
            placeholder="https://your-app.com/mkengage/webhook"
            aria-label={t("webhookUrl")}
            className={`${input} w-full`}
          />
          <div className="flex flex-wrap gap-3">
            {EVENTS.map((event) => (
              <label key={event} className="flex items-center gap-1.5 text-sm">
                <input
                  type="checkbox"
                  checked={webhookEvents.includes(event)}
                  onChange={(changeEvent) =>
                    setWebhookEvents((previous) =>
                      changeEvent.target.checked
                        ? [...previous, event]
                        : previous.filter((existing) => existing !== event),
                    )
                  }
                />
                <code className="text-xs">{event}</code>
              </label>
            ))}
            <button type="submit" disabled={createWebhook.isPending} className={btnPrimary}>
              {t("addWebhook")}
            </button>
          </div>
        </form>
        {newSecret !== null && <Reveal label={t("secretRevealed")} value={newSecret} />}
        {(webhooks.data ?? []).length === 0 && <p className={emptyState}>{t("noWebhooks")}</p>}
        <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
          {(webhooks.data ?? []).map((endpoint) => (
            <li key={endpoint.webhook_endpoint_id} className="flex items-center gap-3 py-2 text-sm">
              <span className="min-w-0 flex-1 truncate font-mono text-xs">{endpoint.url}</span>
              <span className="text-xs text-zinc-500">{endpoint.events.join(", ")}</span>
              <button
                type="button"
                onClick={() => testWebhook.mutate(endpoint.webhook_endpoint_id)}
                className={btnSmall}
              >
                {t("sendTest")}
              </button>
              <button
                type="button"
                onClick={() => deleteWebhook.mutate(endpoint.webhook_endpoint_id)}
                className={btnSmall}
              >
                {t("remove")}
              </button>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
