"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { z } from "zod";

import { btnPrimary, btnSmall, emptyState, input, pageTitle } from "@/lib/ui";

const moderationSchema = z.object({
  profanity: z.object({
    enabled: z.boolean(),
    mask_char: z.string(),
    terms: z.array(z.string()),
  }),
  ip_bans: z.array(
    z.object({
      ip_ban_id: z.string(),
      ip_address: z.string(),
      reason: z.string().nullable(),
      created_at: z.string().nullable(),
    }),
  ),
});

type Moderation = z.infer<typeof moderationSchema>;

async function fetchModeration(): Promise<Moderation> {
  const response = await fetch("/api/cp/moderation", { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed to load (${response.status})`);
  return moderationSchema.parse(await response.json());
}

async function saveProfanity(payload: { enabled: boolean; mask_char: string; terms: string[] }) {
  const response = await fetch("/api/cp/moderation", {
    method: "PUT",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ profanity: payload }),
  });
  if (!response.ok) throw new Error(`Save failed (${response.status})`);
  return moderationSchema.parse(await response.json());
}

async function banIp(payload: { ip_address: string; reason: string | null }) {
  const response = await fetch("/api/cp/moderation/ip-bans", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  if (!response.ok) throw new Error(`Ban failed (${response.status})`);
}

async function unbanIp(id: string) {
  const response = await fetch(`/api/cp/moderation/ip-bans/${id}`, { method: "DELETE" });
  if (!response.ok) throw new Error(`Unban failed (${response.status})`);
}

const panel =
  "space-y-4 rounded-2xl border border-zinc-200 bg-white shadow-sm dark:bg-zinc-900 p-5 dark:border-zinc-800";

function ProfanitySection({ data }: { data: Moderation }) {
  const t = useTranslations("moderation");
  const queryClient = useQueryClient();

  const [enabled, setEnabled] = useState(data.profanity.enabled);
  const [maskChar, setMaskChar] = useState(data.profanity.mask_char || "*");
  const [termsText, setTermsText] = useState(data.profanity.terms.join("\n"));
  const [saved, setSaved] = useState(false);

  const save = useMutation({
    mutationFn: saveProfanity,
    onSuccess: () => {
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
      void queryClient.invalidateQueries({ queryKey: ["moderation"] });
    },
  });

  const terms = termsText
    .split("\n")
    .map((term) => term.trim())
    .filter((term) => term !== "");

  return (
    <section className={panel} aria-labelledby="profanity-h">
      <div>
        <h2 id="profanity-h" className="font-semibold">
          {t("profanityTitle")}
        </h2>
        <p className="text-sm text-zinc-500">{t("profanityHelp")}</p>
      </div>

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={enabled} onChange={(event) => setEnabled(event.target.checked)} />
        {t("profanityEnable")}
      </label>

      <label className="flex items-center gap-2 text-sm">
        {t("maskChar")}
        <input
          type="text"
          maxLength={1}
          value={maskChar}
          onChange={(event) => setMaskChar(event.target.value || "*")}
          aria-label={t("maskChar")}
          className={`${input} w-14 text-center`}
        />
      </label>

      <div className="space-y-1">
        <label htmlFor="terms" className="block text-sm font-medium">
          {t("termsLabel")}{" "}
          <span className="font-normal text-zinc-500">({t("termCount", { count: terms.length })})</span>
        </label>
        <textarea
          id="terms"
          rows={5}
          value={termsText}
          onChange={(event) => setTermsText(event.target.value)}
          placeholder={t("termsPlaceholder")}
          className={`${input} w-full font-mono text-sm`}
        />
        <p className="text-xs text-zinc-500">{t("termsHelp")}</p>
      </div>

      {save.isError && (
        <p className="text-sm text-red-600" role="alert">
          {t("saveError")}
        </p>
      )}

      <button
        type="button"
        disabled={save.isPending}
        onClick={() => save.mutate({ enabled, mask_char: maskChar.slice(0, 1) || "*", terms })}
        className={btnPrimary}
      >
        {save.isPending ? t("saving") : saved ? t("saved") : t("save")}
      </button>
    </section>
  );
}

function BanSection({ data }: { data: Moderation }) {
  const t = useTranslations("moderation");
  const queryClient = useQueryClient();

  const [ip, setIp] = useState("");
  const [reason, setReason] = useState("");

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: ["moderation"] });

  const add = useMutation({
    mutationFn: banIp,
    onSuccess: () => {
      setIp("");
      setReason("");
      invalidate();
    },
  });
  const remove = useMutation({ mutationFn: unbanIp, onSuccess: invalidate });

  return (
    <section className={panel} aria-labelledby="bans-h">
      <div>
        <h2 id="bans-h" className="font-semibold">
          {t("banTitle")}
        </h2>
        <p className="text-sm text-zinc-500">{t("banHelp")}</p>
      </div>

      <form
        className="flex flex-wrap gap-2"
        onSubmit={(event) => {
          event.preventDefault();
          if (add.isPending || ip.trim() === "") return;
          add.mutate({ ip_address: ip.trim(), reason: reason.trim() === "" ? null : reason.trim() });
        }}
      >
        <input
          type="text"
          required
          value={ip}
          onChange={(event) => setIp(event.target.value)}
          placeholder={t("banIpPlaceholder")}
          aria-label={t("banIpLabel")}
          className={`${input} w-44`}
        />
        <input
          type="text"
          maxLength={255}
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          placeholder={t("banReasonPlaceholder")}
          className={`${input} flex-1`}
        />
        <button type="submit" disabled={add.isPending} className={btnPrimary}>
          {add.isPending ? t("banAdding") : t("banAdd")}
        </button>
      </form>
      {add.isError && (
        <p className="text-sm text-red-600" role="alert">
          {t("banError")}
        </p>
      )}

      {data.ip_bans.length === 0 ? (
        <p className={emptyState}>{t("banEmpty")}</p>
      ) : (
        <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
          {data.ip_bans.map((ban) => (
            <li key={ban.ip_ban_id} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
              <code className="shrink-0 rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-xs dark:bg-zinc-800">
                {ban.ip_address}
              </code>
              <div className="min-w-0 flex-1">
                {ban.reason !== null && ban.reason !== "" && (
                  <p className="truncate text-sm">{ban.reason}</p>
                )}
                {ban.created_at !== null && (
                  <p className="text-xs text-zinc-500">
                    {t("bannedOn", { date: new Date(ban.created_at).toLocaleDateString() })}
                  </p>
                )}
              </div>
              <button type="button" onClick={() => remove.mutate(ban.ip_ban_id)} className={btnSmall}>
                {t("banRemove")}
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

export default function ModerationPage() {
  const t = useTranslations("moderation");

  const { data, isPending, isError } = useQuery({
    queryKey: ["moderation"],
    queryFn: fetchModeration,
  });

  return (
    <div className="max-w-2xl space-y-6">
      <div>
        <h1 className={pageTitle}>{t("title")}</h1>
        <p className="text-sm text-zinc-500">{t("subtitle")}</p>
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

      {data !== undefined && (
        <>
          <ProfanitySection data={data} />
          <BanSection data={data} />
        </>
      )}
    </div>
  );
}
