"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { rotatedSecretSchema, widgetSettingsSchema } from "@/lib/api/schemas";

async function fetchSettings() {
  const response = await fetch("/api/cp/organization/widget-settings", { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed to load settings (${response.status})`);
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
    "space-y-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-800";
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
