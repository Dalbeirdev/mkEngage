"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { channelListSchema, channelSchema, type ChannelInfo } from "@/lib/api/schemas";
import { btnPrimary, btnSmall, cardPad, emptyState, input, pageTitle } from "@/lib/ui";

async function fetchChannels() {
  const response = await fetch("/api/cp/channels", { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed to load channels (${response.status})`);
  return channelListSchema.parse(await response.json()).data;
}

async function createChannel(payload: Record<string, unknown>) {
  const response = await fetch("/api/cp/channels", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  if (!response.ok) throw new Error(`Create failed (${response.status})`);
  return channelSchema.parse(await response.json());
}

async function deleteChannel(id: string) {
  const response = await fetch(`/api/cp/channels/${id}`, { method: "DELETE" });
  if (!response.ok) throw new Error(`Delete failed (${response.status})`);
}

function CopyField({ label, value }: { label: string; value: string }) {
  const [copied, setCopied] = useState(false);
  return (
    <div className="flex items-center gap-2 text-xs">
      <span className="w-28 shrink-0 text-zinc-500">{label}</span>
      <code className="min-w-0 flex-1 truncate rounded bg-zinc-100 px-2 py-1 dark:bg-zinc-800">{value}</code>
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
  );
}

export default function ChannelsPage() {
  const t = useTranslations("channels");
  const queryClient = useQueryClient();

  const [type, setType] = useState<"whatsapp" | "telegram" | "messenger">("whatsapp");
  const [name, setName] = useState("");
  const [botToken, setBotToken] = useState("");
  const [pageId, setPageId] = useState("");
  const [phoneNumberId, setPhoneNumberId] = useState("");
  const [wabaId, setWabaId] = useState("");
  const [accessToken, setAccessToken] = useState("");
  const [appSecret, setAppSecret] = useState("");

  const { data, isPending, isError } = useQuery({
    queryKey: ["channels"],
    queryFn: fetchChannels,
  });

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
      invalidate();
    },
  });

  const remove = useMutation({ mutationFn: deleteChannel, onSuccess: invalidate });

  return (
    <div className="max-w-2xl space-y-6">
      <h1 className={pageTitle}>{t("title")}</h1>
      <p className="text-sm text-zinc-500">{t("subtitle")}</p>

      <form
        className={`${cardPad} space-y-3`}
        onSubmit={(event) => {
          event.preventDefault();
          if (create.isPending) return;
          create.mutate(
            type === "telegram"
              ? { type, name: name.trim(), bot_token: botToken.trim() }
              : type === "messenger"
                ? {
                    type,
                    name: name.trim(),
                    page_id: pageId.trim(),
                    access_token: accessToken.trim(),
                    app_secret: appSecret.trim(),
                  }
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
        <h2 className="font-semibold">{t("connectTitle")}</h2>
        <div className="flex gap-2" role="radiogroup" aria-label={t("channelType")}>
          {(["whatsapp", "telegram", "messenger"] as const).map((option) => (
            <button
              key={option}
              type="button"
              role="radio"
              aria-checked={type === option}
              onClick={() => setType(option)}
              className={`rounded-md border px-3 py-1.5 text-sm font-medium capitalize ${
                type === option
                  ? "border-indigo-600 bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"
                  : "border-zinc-300 dark:border-zinc-700"
              }`}
            >
              {option}
            </button>
          ))}
        </div>
        <p className="text-xs text-zinc-500">
          {type === "telegram" ? t("telegramHelp") : type === "messenger" ? t("messengerHelp") : t("connectHelp")}
        </p>
        {type === "telegram" ? (
          <div className="grid gap-3 sm:grid-cols-2">
            <input type="text" required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} placeholder={t("fieldName")} aria-label={t("fieldName")} className={input} />
            <input type="password" required maxLength={128} value={botToken} onChange={(e) => setBotToken(e.target.value)} placeholder={t("fieldBotToken")} aria-label={t("fieldBotToken")} className={input} />
          </div>
        ) : type === "messenger" ? (
          <div className="grid gap-3 sm:grid-cols-2">
            <input type="text" required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} placeholder={t("fieldName")} aria-label={t("fieldName")} className={input} />
            <input type="text" required maxLength={64} value={pageId} onChange={(e) => setPageId(e.target.value)} placeholder={t("fieldPageId")} aria-label={t("fieldPageId")} className={input} />
            <input type="password" required maxLength={512} value={accessToken} onChange={(e) => setAccessToken(e.target.value)} placeholder={t("fieldPageToken")} aria-label={t("fieldPageToken")} className={input} />
            <input type="password" required maxLength={128} value={appSecret} onChange={(e) => setAppSecret(e.target.value)} placeholder={t("fieldAppSecret")} aria-label={t("fieldAppSecret")} className={input} />
          </div>
        ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          <input type="text" required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} placeholder={t("fieldName")} aria-label={t("fieldName")} className={input} />
          <input type="text" required maxLength={64} value={phoneNumberId} onChange={(e) => setPhoneNumberId(e.target.value)} placeholder={t("fieldPhoneNumberId")} aria-label={t("fieldPhoneNumberId")} className={input} />
          <input type="text" maxLength={64} value={wabaId} onChange={(e) => setWabaId(e.target.value)} placeholder={t("fieldWabaId")} aria-label={t("fieldWabaId")} className={input} />
          <input type="password" required maxLength={512} value={accessToken} onChange={(e) => setAccessToken(e.target.value)} placeholder={t("fieldAccessToken")} aria-label={t("fieldAccessToken")} className={input} />
          <input type="password" required maxLength={128} value={appSecret} onChange={(e) => setAppSecret(e.target.value)} placeholder={t("fieldAppSecret")} aria-label={t("fieldAppSecret")} className={input} />
        </div>
        )}
        {create.isError && (
          <p className="text-sm text-red-600" role="alert">
            {t("createError")}
          </p>
        )}
        <button type="submit" disabled={create.isPending} className={btnPrimary}>
          {create.isPending ? t("connecting") : t("connect")}
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
      {data !== undefined && data.length === 0 && <p className={emptyState}>{t("empty")}</p>}

      {(data ?? []).map((channel: ChannelInfo) => (
        <section key={channel.channel_id} className={`${cardPad} space-y-2`}>
          <div className="flex items-center gap-3">
            <span aria-hidden>{channel.type === "telegram" ? "✈️" : channel.type === "messenger" ? "📨" : "💬"}</span>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium">
                {channel.name}
                <span className="ms-2 rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-800 dark:bg-green-900/40 dark:text-green-300">
                  {channel.status}
                </span>
              </p>
              <p className="text-xs uppercase tracking-wide text-zinc-500">{channel.type}</p>
            </div>
            <button type="button" onClick={() => remove.mutate(channel.channel_id)} className={btnSmall}>
              {t("disconnect")}
            </button>
          </div>
          <p className="text-xs text-zinc-500">{channel.type === "telegram" ? t("telegramSetupHint") : channel.type === "messenger" ? t("messengerSetupHint") : t("metaSetupHint")}</p>
          <CopyField label={t("webhookUrl")} value={channel.webhook_url} />
          <CopyField label={t("verifyToken")} value={channel.webhook_verify_token} />
        </section>
      ))}
    </div>
  );
}
