"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { cardShell } from "@/components/metric-card";
import { channelListSchema, channelSchema, type ChannelInfo } from "@/lib/api/schemas";

type ChannelType = "whatsapp" | "telegram" | "messenger" | "instagram";

const TYPE_META: Record<string, { label: string; tile: string; short: string }> = {
  whatsapp: { label: "WhatsApp", tile: "bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300", short: "WA" },
  telegram: { label: "Telegram", tile: "bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300", short: "TG" },
  messenger: { label: "Messenger", tile: "bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300", short: "MS" },
  instagram: { label: "Instagram", tile: "bg-pink-100 text-pink-700 dark:bg-pink-500/15 dark:text-pink-300", short: "IG" },
};

const inputCls =
  "min-w-0 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900";

async function fetchChannels() {
  const res = await fetch("/api/cp/channels", { cache: "no-store" });
  if (!res.ok) throw new Error(`Failed to load channels (${res.status})`);
  return channelListSchema.parse(await res.json()).data;
}

async function createChannel(payload: Record<string, unknown>) {
  const res = await fetch("/api/cp/channels", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  if (!res.ok) throw new Error(`Create failed (${res.status})`);
  return channelSchema.parse(await res.json());
}

async function deleteChannel(id: string) {
  const res = await fetch(`/api/cp/channels/${id}`, { method: "DELETE" });
  if (!res.ok) throw new Error(`Delete failed (${res.status})`);
}

function fmtDate(iso: string | null): string {
  if (iso === null) return "—";
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "—" : d.toLocaleString("en-US", { year: "numeric", month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
}

function CopyField({ label, value, secret = false }: { label: string; value: string; secret?: boolean }) {
  const [copied, setCopied] = useState(false);
  const [revealed, setRevealed] = useState(!secret);
  const shown = revealed ? value : "•".repeat(Math.min(28, value.length)) + value.slice(-4);
  return (
    <div className="flex items-center gap-2 text-xs">
      <span className="w-24 shrink-0 text-zinc-500">{label}</span>
      <code className="min-w-0 flex-1 truncate rounded-md bg-zinc-100 px-2 py-1.5 font-mono dark:bg-zinc-800">{shown}</code>
      {secret && (
        <button
          type="button"
          onClick={() => setRevealed((v) => !v)}
          aria-label={revealed ? "Hide" : "Reveal"}
          className="rounded-md border border-zinc-200 px-2 py-1 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
        >
          {revealed ? "Hide" : "Show"}
        </button>
      )}
      <button
        type="button"
        onClick={() => {
          void navigator.clipboard.writeText(value).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
          });
        }}
        className="rounded-md border border-zinc-200 px-2 py-1 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
      >
        {copied ? "Copied" : "Copy"}
      </button>
    </div>
  );
}

export default function ChannelsPage() {
  const queryClient = useQueryClient();

  const [type, setType] = useState<ChannelType>("whatsapp");
  const [name, setName] = useState("");
  const [botToken, setBotToken] = useState("");
  const [pageId, setPageId] = useState("");
  const [phoneNumberId, setPhoneNumberId] = useState("");
  const [wabaId, setWabaId] = useState("");
  const [accessToken, setAccessToken] = useState("");
  const [appSecret, setAppSecret] = useState("");
  const [igId, setIgId] = useState("");

  const { data, isPending, isError } = useQuery({ queryKey: ["channels"], queryFn: fetchChannels });
  const invalidate = () => void queryClient.invalidateQueries({ queryKey: ["channels"] });

  const create = useMutation({
    mutationFn: createChannel,
    onSuccess: () => {
      setName("");
      setPhoneNumberId("");
      setWabaId("");
      setAccessToken("");
      setAppSecret("");
      setBotToken("");
      setPageId("");
      setIgId("");
      invalidate();
    },
  });
  const remove = useMutation({ mutationFn: deleteChannel, onSuccess: invalidate });

  const helpText =
    type === "telegram"
      ? "Paste your @BotFather bot token. We register the webhook automatically."
      : type === "messenger"
        ? "Connect a Facebook Page with messaging enabled and admin access."
        : type === "instagram"
          ? "Connect an Instagram professional account linked to a Facebook Page."
          : "Connect your WhatsApp Cloud API number. Data is stored encrypted.";

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Channels</h1>
          <p className="mt-1 text-sm text-zinc-500">
            Connect messaging channels to converse with your audience across every inbox.
          </p>
        </div>
      </div>

      {/* Connect a channel */}
      <section className={`space-y-4 ${cardShell}`}>
        <h2 className="text-sm font-semibold">Connect a channel</h2>
        <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Channel type">
          {(["whatsapp", "telegram", "messenger", "instagram"] as const).map((option) => (
            <button
              key={option}
              type="button"
              role="radio"
              aria-checked={type === option}
              onClick={() => setType(option)}
              className={`inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors ${
                type === option
                  ? "border-indigo-600 bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300"
                  : "border-zinc-200 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
              }`}
            >
              <span className={`grid size-5 place-items-center rounded text-[10px] font-bold ${TYPE_META[option].tile}`} aria-hidden>
                {TYPE_META[option].short}
              </span>
              {TYPE_META[option].label}
            </button>
          ))}
        </div>
        <p className="text-xs text-zinc-500">{helpText}</p>

        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault();
            if (create.isPending) return;
            create.mutate(
              type === "telegram"
                ? { type, name: name.trim(), bot_token: botToken.trim() }
                : type === "messenger"
                  ? { type, name: name.trim(), page_id: pageId.trim(), access_token: accessToken.trim(), app_secret: appSecret.trim() }
                  : type === "instagram"
                    ? { type, name: name.trim(), ig_id: igId.trim() === "" ? null : igId.trim(), access_token: accessToken.trim(), app_secret: appSecret.trim() }
                    : {
                      type,
                      name: name.trim(),
                      phone_number_id: phoneNumberId.trim(),
                      waba_id: wabaId.trim() === "" ? null : wabaId.trim(),
                      access_token: accessToken.trim(),
                      app_secret: appSecret.trim(),
                    },
            );
          }}
        >
          <div className="grid gap-3 sm:grid-cols-2">
            <input type="text" required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} placeholder="Channel name" aria-label="Channel name" className={inputCls} />
            {type === "telegram" && (
              <input type="password" required maxLength={128} value={botToken} onChange={(e) => setBotToken(e.target.value)} placeholder="Bot token" aria-label="Bot token" className={inputCls} />
            )}
            {type === "messenger" && (
              <>
                <input type="text" required maxLength={64} value={pageId} onChange={(e) => setPageId(e.target.value)} placeholder="Page ID" aria-label="Page ID" className={inputCls} />
                <input type="password" required maxLength={512} value={accessToken} onChange={(e) => setAccessToken(e.target.value)} placeholder="Page access token" aria-label="Page access token" className={inputCls} />
                <input type="password" required maxLength={128} value={appSecret} onChange={(e) => setAppSecret(e.target.value)} placeholder="App secret" aria-label="App secret" className={inputCls} />
              </>
            )}
            {type === "instagram" && (
              <>
                <input type="text" maxLength={64} value={igId} onChange={(e) => setIgId(e.target.value)} placeholder="Instagram account ID (optional)" aria-label="Instagram account ID" className={inputCls} />
                <input type="password" required maxLength={512} value={accessToken} onChange={(e) => setAccessToken(e.target.value)} placeholder="Page access token" aria-label="Page access token" className={inputCls} />
                <input type="password" required maxLength={128} value={appSecret} onChange={(e) => setAppSecret(e.target.value)} placeholder="App secret" aria-label="App secret" className={inputCls} />
              </>
            )}
            {type === "whatsapp" && (
              <>
                <input type="text" required maxLength={64} value={phoneNumberId} onChange={(e) => setPhoneNumberId(e.target.value)} placeholder="Phone number ID" aria-label="Phone number ID" className={inputCls} />
                <input type="text" maxLength={64} value={wabaId} onChange={(e) => setWabaId(e.target.value)} placeholder="WABA ID (optional)" aria-label="WABA ID" className={inputCls} />
                <input type="password" required maxLength={512} value={accessToken} onChange={(e) => setAccessToken(e.target.value)} placeholder="Access token" aria-label="Access token" className={inputCls} />
                <input type="password" required maxLength={128} value={appSecret} onChange={(e) => setAppSecret(e.target.value)} placeholder="App secret" aria-label="App secret" className={inputCls} />
              </>
            )}
          </div>
          {create.isError && <p className="text-sm text-red-600" role="alert">Couldn’t connect that channel — check the credentials.</p>}
          <div className="flex items-center justify-between gap-3">
            <button
              type="submit"
              disabled={create.isPending}
              className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:opacity-60"
            >
              {create.isPending ? "Connecting…" : "Connect channel"}
            </button>
            <span className="text-xs text-zinc-400">🔒 Your data is stored encrypted.</span>
          </div>
        </form>
      </section>

      {isPending && <p className="text-sm text-zinc-500" role="status">Loading channels…</p>}
      {isError && <p className="text-sm text-red-600" role="alert">Couldn’t load channels.</p>}
      {data !== undefined && data.length === 0 && (
        <p className="rounded-2xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700">
          No channels connected yet.
        </p>
      )}

      {(data ?? []).map((channel: ChannelInfo) => {
        const meta = TYPE_META[channel.type] ?? { label: channel.type, tile: "bg-zinc-100 text-zinc-600", short: "?" };
        return (
          <section key={channel.channel_id} className={`space-y-3 ${cardShell}`}>
            <div className="flex flex-wrap items-center gap-3">
              <span className={`grid size-10 shrink-0 place-items-center rounded-xl text-sm font-bold ${meta.tile}`} aria-hidden>
                {meta.short}
              </span>
              <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 font-medium">
                  {channel.name}
                  <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                    {channel.status}
                  </span>
                </p>
                <p className="text-xs text-zinc-400">
                  Channels › {meta.label} › Webhook
                </p>
              </div>
              <div className="text-end">
                <button
                  type="button"
                  onClick={() => remove.mutate(channel.channel_id)}
                  className="rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 transition-colors hover:bg-red-50 dark:border-red-900/50 dark:hover:bg-red-950/40"
                >
                  Disconnect
                </button>
                <p className="mt-1 text-xs text-zinc-400">Connected {fmtDate(channel.created_at)}</p>
              </div>
            </div>
            <div className="space-y-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
              <CopyField label="Webhook URL" value={channel.webhook_url} />
              <CopyField label="Verify token" value={channel.webhook_verify_token} secret />
            </div>
          </section>
        );
      })}
    </div>
  );
}
