"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import {
  rotatedSecretSchema,
  widgetSettingsSchema,
  type Trigger,
  type WidgetSettings,
} from "@/lib/api/schemas";

async function fetchSettings() {
  const response = await fetch("/api/cp/organization/widget-settings", { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed to load settings (${response.status})`);
  return widgetSettingsSchema.parse(await response.json());
}

const DAYS = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"] as const;

/** "09:00-17:00, 13:30-18:00" ⇄ [["09:00","17:00"],["13:30","18:00"]] */
function parseRanges(text: string): Array<[string, string]> | null {
  const trimmed = text.trim();
  if (trimmed === "") return [];
  const ranges: Array<[string, string]> = [];
  for (const part of trimmed.split(",")) {
    const match = /^\s*([01]\d|2[0-3]):([0-5]\d)\s*-\s*([01]\d|2[0-3]):([0-5]\d)\s*$/.exec(part);
    if (match === null) return null;
    ranges.push([`${match[1]}:${match[2]}`, `${match[3]}:${match[4]}`]);
  }
  return ranges;
}

function formatRanges(ranges: Array<[string, string]> | undefined): string {
  return (ranges ?? []).map(([start, end]) => `${start}-${end}`).join(", ");
}

async function saveEngagement(payload: {
  prechat: WidgetSettings["prechat"];
  business_hours: WidgetSettings["business_hours"];
  triggers: Trigger[];
}) {
  const response = await fetch("/api/cp/organization/widget-settings", {
    method: "PUT",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  if (!response.ok) throw new Error(`Save failed (${response.status})`);
  return widgetSettingsSchema.parse(await response.json());
}

async function rotateSecret() {
  const response = await fetch("/api/cp/organization/widget-settings/rotate-secret", {
    method: "POST",
  });
  if (!response.ok) throw new Error(`Rotate failed (${response.status})`);
  return rotatedSecretSchema.parse(await response.json());
}

function CopyButton({ text, label, copied }: { text: string; label: string; copied: string }) {
  const [done, setDone] = useState(false);

  return (
    <button
      type="button"
      onClick={() => {
        void navigator.clipboard.writeText(text).then(() => {
          setDone(true);
          setTimeout(() => setDone(false), 2000);
        });
      }}
      className="rounded-md border border-zinc-300 px-3 py-1 text-xs font-medium hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:hover:bg-zinc-800"
    >
      {done ? copied : label}
    </button>
  );
}

function EngagementSection({ settings }: { settings: WidgetSettings }) {
  const t = useTranslations("widgetSettings");
  const queryClient = useQueryClient();

  const [prechatEnabled, setPrechatEnabled] = useState(settings.prechat.enabled);
  const [requireEmail, setRequireEmail] = useState(settings.prechat.require_email);
  const [hoursEnabled, setHoursEnabled] = useState(settings.business_hours.enabled);
  const [timezone, setTimezone] = useState(settings.business_hours.timezone);
  const [dayText, setDayText] = useState<Record<string, string>>(() =>
    Object.fromEntries(
      DAYS.map((day) => [day, formatRanges(settings.business_hours.schedule[day])]),
    ),
  );
  const [triggers, setTriggers] = useState<Trigger[]>(settings.triggers);
  const [saved, setSaved] = useState(false);
  const [parseError, setParseError] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: saveEngagement,
    onSuccess: () => {
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
      void queryClient.invalidateQueries({ queryKey: ["widget-settings"] });
    },
  });

  const onSave = () => {
    const schedule: Record<string, Array<[string, string]>> = {};
    for (const day of DAYS) {
      const ranges = parseRanges(dayText[day] ?? "");
      if (ranges === null) {
        setParseError(t("hoursInvalid", { day: t(`day_${day}`) }));
        return;
      }
      schedule[day] = ranges;
    }
    setParseError(null);
    save.mutate({
      prechat: { enabled: prechatEnabled, require_email: requireEmail },
      business_hours: { enabled: hoursEnabled, timezone, schedule },
      triggers: triggers.filter((trigger) => trigger.message.trim() !== ""),
    });
  };

  const updateTrigger = (index: number, patch: Partial<Trigger>) => {
    setTriggers((previous) =>
      previous.map((trigger, i) => (i === index ? { ...trigger, ...patch } : trigger)),
    );
  };

  const panelClass =
    "space-y-3 rounded-2xl border border-zinc-200 bg-white shadow-sm dark:bg-zinc-900 p-5 dark:border-zinc-800";
  const inputClass =
    "rounded-md border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-950";

  return (
    <section className={panelClass} aria-labelledby="engagement-h">
      <h2 id="engagement-h" className="font-semibold">
        {t("engagementTitle")}
      </h2>

      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">{t("prechatTitle")}</legend>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={prechatEnabled}
            onChange={(event) => setPrechatEnabled(event.target.checked)}
          />
          {t("prechatEnable")}
        </label>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={requireEmail}
            disabled={!prechatEnabled}
            onChange={(event) => setRequireEmail(event.target.checked)}
          />
          {t("prechatRequireEmail")}
        </label>
      </fieldset>

      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">{t("hoursTitle")}</legend>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={hoursEnabled}
            onChange={(event) => setHoursEnabled(event.target.checked)}
          />
          {t("hoursEnable")}
        </label>
        <label className="flex items-center gap-2 text-sm">
          {t("hoursTimezone")}
          <input
            type="text"
            className={inputClass}
            value={timezone}
            disabled={!hoursEnabled}
            onChange={(event) => setTimezone(event.target.value)}
            placeholder="Europe/London"
          />
        </label>
        <p className="text-xs text-zinc-500">{t("hoursHelp")}</p>
        <div className="grid grid-cols-1 gap-1 sm:grid-cols-2">
          {DAYS.map((day) => (
            <label key={day} className="flex items-center gap-2 text-sm">
              <span className="w-10 shrink-0 text-zinc-500">{t(`day_${day}`)}</span>
              <input
                type="text"
                className={`${inputClass} min-w-0 flex-1`}
                value={dayText[day] ?? ""}
                disabled={!hoursEnabled}
                onChange={(event) =>
                  setDayText((previous) => ({ ...previous, [day]: event.target.value }))
                }
                placeholder={t("hoursClosed")}
              />
            </label>
          ))}
        </div>
      </fieldset>

      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">{t("triggersTitle")}</legend>
        <p className="text-xs text-zinc-500">{t("triggersHelp")}</p>
        {triggers.map((trigger, index) => (
          <div
            key={trigger.id}
            className="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800"
          >
            <div className="flex flex-wrap items-center gap-2">
              <label className="flex items-center gap-1.5 text-sm">
                <input
                  type="checkbox"
                  checked={trigger.enabled}
                  onChange={(event) => updateTrigger(index, { enabled: event.target.checked })}
                />
                {t("triggerEnabled")}
              </label>
              <select
                value={trigger.type}
                onChange={(event) =>
                  updateTrigger(index, { type: event.target.value as Trigger["type"] })
                }
                className={inputClass}
                aria-label={t("triggerType")}
              >
                <option value="time_on_page">{t("triggerTypeTime")}</option>
                <option value="url_match">{t("triggerTypeUrl")}</option>
              </select>
              {trigger.type === "time_on_page" ? (
                <label className="flex items-center gap-1.5 text-sm">
                  {t("triggerAfter")}
                  <input
                    type="number"
                    min={0}
                    max={3600}
                    value={trigger.seconds ?? 10}
                    onChange={(event) =>
                      updateTrigger(index, { seconds: Number(event.target.value) })
                    }
                    className={`${inputClass} w-20`}
                  />
                  {t("triggerSeconds")}
                </label>
              ) : (
                <input
                  type="text"
                  value={trigger.url_pattern ?? ""}
                  onChange={(event) => updateTrigger(index, { url_pattern: event.target.value })}
                  placeholder="/pricing"
                  aria-label={t("triggerUrlPattern")}
                  className={`${inputClass} flex-1`}
                />
              )}
              <button
                type="button"
                onClick={() => setTriggers((previous) => previous.filter((_, i) => i !== index))}
                className="ms-auto rounded-md border border-zinc-300 px-2 py-1 text-xs hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
              >
                {t("triggerRemove")}
              </button>
            </div>
            <input
              type="text"
              value={trigger.message}
              maxLength={500}
              onChange={(event) => updateTrigger(index, { message: event.target.value })}
              placeholder={t("triggerMessagePlaceholder")}
              aria-label={t("triggerMessage")}
              className={`${inputClass} w-full`}
            />
          </div>
        ))}
        <button
          type="button"
          onClick={() =>
            setTriggers((previous) => [
              ...previous,
              {
                id: `t-${Date.now().toString(36)}`,
                enabled: true,
                type: "time_on_page",
                seconds: 10,
                message: "",
              },
            ])
          }
          className="rounded-md border border-zinc-300 px-3 py-1 text-xs font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
        >
          {t("triggerAdd")}
        </button>
      </fieldset>

      {parseError !== null && (
        <p className="text-sm text-red-600" role="alert">
          {parseError}
        </p>
      )}
      {save.isError && (
        <p className="text-sm text-red-600" role="alert">
          {t("saveError")}
        </p>
      )}

      <button
        type="button"
        disabled={save.isPending}
        onClick={onSave}
        className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-60"
      >
        {save.isPending ? t("saving") : saved ? t("savedNotice") : t("save")}
      </button>
    </section>
  );
}

export default function WidgetSettingsPage() {
  const t = useTranslations("widgetSettings");
  const queryClient = useQueryClient();
  const [newSecret, setNewSecret] = useState<string | null>(null);

  const { data, isPending, isError } = useQuery({
    queryKey: ["widget-settings"],
    queryFn: fetchSettings,
  });

  const rotate = useMutation({
    mutationFn: rotateSecret,
    onSuccess: (result) => {
      setNewSecret(result.signing_secret);
      void queryClient.invalidateQueries({ queryKey: ["widget-settings"] });
    },
  });

  if (isPending) {
    return (
      <p className="text-sm text-zinc-500" role="status">
        {t("loading")}
      </p>
    );
  }

  if (isError || data === undefined) {
    return (
      <p className="text-sm text-red-600" role="alert">
        {t("error")}
      </p>
    );
  }

  const siteKey = data.site_key ?? "";
  const embedSnippet = [
    `<script async src="https://cdn.mkengage.example/widget/mkengage-widget.iife.js"`,
    `        data-mkengage data-site-key="${siteKey}"`,
    `        data-api-url="https://api.mkengage.example"></script>`,
  ].join("\n");
  const hmacExample = [
    `// Server-side (PHP example — SDK helpers in integrations/):`,
    `$signature = hash_hmac('sha256', $user->id, $widgetSigningSecret);`,
    ``,
    `// Then in the page:`,
    `mount({ siteKey, apiUrl, identity: {`,
    `  externalId: userId, signature, name, email,`,
    `}});`,
  ].join("\n");

  const panelClass =
    "space-y-3 rounded-2xl border border-zinc-200 bg-white shadow-sm dark:bg-zinc-900 p-5 dark:border-zinc-800";
  const preClass =
    "overflow-x-auto rounded-md bg-zinc-100 p-3 font-mono text-xs leading-relaxed dark:bg-zinc-900";

  return (
    <div className="max-w-2xl space-y-6">
      <h1 className="text-2xl font-bold tracking-tight">{t("title")}</h1>

      <section className={panelClass} aria-labelledby="site-key-h">
        <div className="flex items-center justify-between gap-3">
          <h2 id="site-key-h" className="font-semibold">
            {t("siteKeyTitle")}
          </h2>
          <CopyButton text={siteKey} label={t("copy")} copied={t("copied")} />
        </div>
        <p className="text-sm text-zinc-500">{t("siteKeyHelp")}</p>
        <code className="block break-all rounded-md bg-zinc-100 px-3 py-2 font-mono text-sm dark:bg-zinc-900">
          {siteKey}
        </code>
      </section>

      <EngagementSection settings={data} />

      <section className={panelClass} aria-labelledby="embed-h">
        <div className="flex items-center justify-between gap-3">
          <h2 id="embed-h" className="font-semibold">
            {t("embedTitle")}
          </h2>
          <CopyButton text={embedSnippet} label={t("copy")} copied={t("copied")} />
        </div>
        <p className="text-sm text-zinc-500">{t("embedHelp")}</p>
        <pre className={preClass}>{embedSnippet}</pre>
      </section>

      <section className={panelClass} aria-labelledby="identity-h">
        <div className="flex items-center justify-between gap-3">
          <h2 id="identity-h" className="font-semibold">
            {t("identityTitle")}
          </h2>
          <button
            type="button"
            disabled={rotate.isPending}
            onClick={() => {
              if (window.confirm(t("rotateConfirm"))) rotate.mutate();
            }}
            className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-60"
          >
            {rotate.isPending ? t("rotating") : t("rotate")}
          </button>
        </div>
        <p className="text-sm text-zinc-500">
          {data.signing_configured ? t("identityConfigured") : t("identityNotConfigured")}
        </p>

        {newSecret !== null && (
          <div
            role="alert"
            className="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950"
          >
            <p className="text-sm font-semibold text-amber-900 dark:text-amber-200">
              {t("newSecretTitle")}
            </p>
            <div className="flex items-center gap-2">
              <code className="min-w-0 flex-1 break-all rounded bg-white px-2 py-1 font-mono text-xs dark:bg-zinc-900">
                {newSecret}
              </code>
              <CopyButton text={newSecret} label={t("copy")} copied={t("copied")} />
            </div>
            <p className="text-xs text-amber-800 dark:text-amber-300">{t("newSecretHelp")}</p>
          </div>
        )}

        <p className="text-sm text-zinc-500">{t("identityHelp")}</p>
        <pre className={preClass}>{hmacExample}</pre>
      </section>
    </div>
  );
}
