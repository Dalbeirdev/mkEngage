"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { slackIntegrationSchema } from "@/lib/api/schemas";

async function fetchSlack() {
  const res = await fetch("/api/cp/integrations/slack", { cache: "no-store" });
  if (!res.ok) throw new Error(`Failed to load (${res.status})`);
  return slackIntegrationSchema.parse(await res.json()).slack;
}

const cardShell =
  "rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900";
const input =
  "w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950";

export default function IntegrationsPage() {
  const qc = useQueryClient();
  const { data, isPending, isError } = useQuery({ queryKey: ["integrations", "slack"], queryFn: fetchSlack });

  const [enabled, setEnabled] = useState(false);
  const [webhookUrl, setWebhookUrl] = useState("");
  const [saved, setSaved] = useState(false);
  const [testResult, setTestResult] = useState<string | null>(null);

  // Sync local state to the loaded config once, without an effect.
  const [synced, setSynced] = useState(false);
  if (data !== undefined && !synced) {
    setSynced(true);
    setEnabled(data.enabled);
  }

  const save = useMutation({
    mutationFn: async () => {
      const res = await fetch("/api/cp/integrations/slack", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          enabled,
          ...(webhookUrl.trim() !== "" ? { webhook_url: webhookUrl.trim() } : {}),
        }),
      });
      if (!res.ok) throw new Error(res.status === 422 ? "Enter a valid https webhook URL." : `Failed (${res.status})`);
    },
    onSuccess: () => {
      setSaved(true);
      setWebhookUrl("");
      setTimeout(() => setSaved(false), 2500);
      void qc.invalidateQueries({ queryKey: ["integrations", "slack"] });
    },
  });

  const test = useMutation({
    mutationFn: async () => {
      setTestResult(null);
      const res = await fetch("/api/cp/integrations/slack/test", { method: "POST" });
      const body = (await res.json().catch(() => ({}))) as { delivered?: boolean };
      if (!res.ok || body.delivered !== true) throw new Error("Test failed — check the webhook URL.");
    },
    onSuccess: () => setTestResult("Test message delivered to Slack."),
    onError: (e: Error) => setTestResult(e.message),
  });

  return (
    <div className="max-w-2xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Integrations</h1>
        <p className="mt-1 text-sm text-zinc-500">Connect mkEngage to the tools your team already uses.</p>
      </div>

      <section className={`space-y-4 p-5 ${cardShell}`} aria-labelledby="slack-h">
        <div className="flex items-center gap-3">
          <span aria-hidden className="grid size-9 place-items-center rounded-lg bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300">#</span>
          <div className="flex-1">
            <h2 id="slack-h" className="font-semibold">Slack</h2>
            <p className="text-sm text-zinc-500">Post a notification to a Slack channel when a new conversation starts.</p>
          </div>
          {data?.configured === true && (
            <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
              Connected
            </span>
          )}
        </div>

        {isPending && <p className="text-sm text-zinc-500" role="status">Loading…</p>}
        {isError && <p className="text-sm text-red-600" role="alert">Couldn’t load the integration.</p>}

        {data !== undefined && (
          <>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
              Enable Slack notifications
            </label>

            <div className="space-y-1">
              <label htmlFor="hook" className="block text-sm font-medium">
                Incoming webhook URL {data.configured && <span className="font-normal text-zinc-400">· leave blank to keep the current one</span>}
              </label>
              <input
                id="hook"
                type="url"
                value={webhookUrl}
                onChange={(e) => setWebhookUrl(e.target.value)}
                placeholder="https://hooks.slack.com/services/…"
                className={input}
              />
              <p className="text-xs text-zinc-500">
                Create one in Slack under <span className="font-medium">Incoming Webhooks</span>. Stored write-only — it’s never shown again.
              </p>
            </div>

            {save.isError && <p className="text-sm text-red-600" role="alert">{(save.error as Error).message}</p>}

            <div className="flex flex-wrap items-center gap-2">
              <button
                type="button"
                disabled={save.isPending}
                onClick={() => save.mutate()}
                className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60"
              >
                {save.isPending ? "Saving…" : saved ? "Saved" : "Save"}
              </button>
              <button
                type="button"
                disabled={test.isPending || !data.configured}
                onClick={() => test.mutate()}
                className="rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium hover:bg-zinc-100 disabled:opacity-60 dark:border-zinc-700 dark:hover:bg-zinc-800"
              >
                {test.isPending ? "Sending…" : "Send test"}
              </button>
              {testResult !== null && (
                <span className={`text-sm ${test.isError ? "text-red-600" : "text-emerald-600"}`} role="status">
                  {testResult}
                </span>
              )}
            </div>
          </>
        )}
      </section>
    </div>
  );
}
