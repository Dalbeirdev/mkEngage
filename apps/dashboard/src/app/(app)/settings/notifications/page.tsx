"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { z } from "zod";

const notificationsSchema = z.object({
  missed_email_enabled: z.boolean(),
  missed_after_minutes: z.number().int(),
});
type NotificationSettings = z.infer<typeof notificationsSchema>;

async function fetchSettings(): Promise<NotificationSettings> {
  const res = await fetch("/api/cp/organization/notifications", { cache: "no-store" });
  if (!res.ok) throw new Error(`Failed to load (${res.status})`);
  return notificationsSchema.parse(await res.json());
}

const panel =
  "space-y-4 rounded-2xl border border-zinc-200 bg-white shadow-sm dark:bg-zinc-900 p-5 dark:border-zinc-800";
const input =
  "w-24 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950";

function NotificationsForm({ data }: { data: NotificationSettings }) {
  const qc = useQueryClient();
  const [enabled, setEnabled] = useState(data.missed_email_enabled);
  const [minutes, setMinutes] = useState(data.missed_after_minutes.toString());
  const [saved, setSaved] = useState(false);

  const save = useMutation({
    mutationFn: async () => {
      const parsed = Number(minutes);
      const res = await fetch("/api/cp/organization/notifications", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          missed_email_enabled: enabled,
          ...(Number.isInteger(parsed) && parsed >= 1 && parsed <= 120
            ? { missed_after_minutes: parsed }
            : {}),
        }),
      });
      if (!res.ok) throw new Error(`Save failed (${res.status})`);
    },
    onSuccess: () => {
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
      void qc.invalidateQueries({ queryKey: ["notification-settings"] });
    },
  });

  return (
    <section className={panel} aria-labelledby="notif-h">
      <div>
        <h2 id="notif-h" className="font-semibold">Missed-conversation emails</h2>
        <p className="text-sm text-zinc-500">
          When a customer&apos;s message sits unanswered past the threshold, the assigned agent
          (or the whole team, if unassigned) gets an email with a link to the conversation.
          Requires outbound email (SMTP) to be configured on the server.
        </p>
      </div>

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
        Email agents about unanswered conversations
      </label>

      <label className="flex items-center gap-2 text-sm">
        <span className="font-medium">Notify after</span>
        <input
          type="number"
          min={1}
          max={120}
          value={minutes}
          disabled={!enabled}
          onChange={(e) => setMinutes(e.target.value)}
          aria-label="Minutes before notifying"
          className={input}
        />
        <span className="text-zinc-500">minutes without a reply</span>
      </label>

      {save.isError && (
        <p className="text-sm text-red-600" role="alert">Couldn&apos;t save. Minutes must be 1–120.</p>
      )}

      <button
        type="button"
        disabled={save.isPending}
        onClick={() => save.mutate()}
        className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60"
      >
        {save.isPending ? "Saving…" : saved ? "Saved" : "Save"}
      </button>
    </section>
  );
}

export default function NotificationSettingsPage() {
  const { data, isPending, isError } = useQuery({
    queryKey: ["notification-settings"],
    queryFn: fetchSettings,
  });

  return (
    <div className="max-w-2xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Notifications</h1>
        <p className="mt-1 text-sm text-zinc-500">
          Keep the team responsive even when nobody is watching the inbox.
        </p>
      </div>

      {isPending && <p className="text-sm text-zinc-500" role="status">Loading…</p>}
      {isError && <p className="text-sm text-red-600" role="alert">Couldn&apos;t load the settings.</p>}
      {data !== undefined && <NotificationsForm data={data} />}
    </div>
  );
}
