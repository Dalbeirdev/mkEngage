"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { z } from "zod";

const slaSchema = z.object({
  enabled: z.boolean(),
  targets: z.object({
    urgent: z.number().int().nullable(),
    high: z.number().int().nullable(),
    normal: z.number().int().nullable(),
    low: z.number().int().nullable(),
  }),
});
type Sla = z.infer<typeof slaSchema>;

const PRIORITIES = [
  { key: "urgent", label: "Urgent", hint: "e.g. 15" },
  { key: "high", label: "High", hint: "e.g. 60" },
  { key: "normal", label: "Normal", hint: "e.g. 240" },
  { key: "low", label: "Low", hint: "no target" },
] as const;

async function fetchSla(): Promise<Sla> {
  const res = await fetch("/api/cp/organization/sla", { cache: "no-store" });
  if (!res.ok) throw new Error(`Failed to load (${res.status})`);
  return slaSchema.parse(await res.json());
}

const panel =
  "space-y-4 rounded-2xl border border-zinc-200 bg-white shadow-sm dark:bg-zinc-900 p-5 dark:border-zinc-800";
const input =
  "w-24 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950";

function SlaForm({ data }: { data: Sla }) {
  const qc = useQueryClient();
  const [enabled, setEnabled] = useState(data.enabled);
  const [targets, setTargets] = useState<Record<string, string>>({
    urgent: data.targets.urgent?.toString() ?? "",
    high: data.targets.high?.toString() ?? "",
    normal: data.targets.normal?.toString() ?? "",
    low: data.targets.low?.toString() ?? "",
  });
  const [saved, setSaved] = useState(false);

  const save = useMutation({
    mutationFn: async () => {
      const res = await fetch("/api/cp/organization/sla", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          enabled,
          targets: Object.fromEntries(
            PRIORITIES.map(({ key }) => {
              const raw = targets[key]?.trim() ?? "";
              const minutes = Number(raw);
              return [key, raw !== "" && Number.isInteger(minutes) && minutes >= 1 ? minutes : null];
            }),
          ),
        }),
      });
      if (!res.ok) throw new Error(`Save failed (${res.status})`);
    },
    onSuccess: () => {
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
      void qc.invalidateQueries({ queryKey: ["sla"] });
    },
  });

  return (
    <section className={panel} aria-labelledby="sla-h">
      <div>
        <h2 id="sla-h" className="font-semibold">First-response targets</h2>
        <p className="text-sm text-zinc-500">
          Minutes an agent has to send the first reply, per priority. Conversations past their target show a red SLA chip in the inbox. Leave a field blank for no target.
        </p>
      </div>

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
        Enable SLA tracking
      </label>

      <div className="grid gap-3 sm:grid-cols-2">
        {PRIORITIES.map(({ key, label, hint }) => (
          <label key={key} className="flex items-center justify-between gap-2 text-sm">
            <span className="font-medium">{label}</span>
            <span className="flex items-center gap-1.5 text-zinc-500">
              <input
                type="number"
                min={1}
                max={10080}
                value={targets[key] ?? ""}
                disabled={!enabled}
                onChange={(e) => setTargets((prev) => ({ ...prev, [key]: e.target.value }))}
                placeholder={hint}
                aria-label={`${label} target minutes`}
                className={input}
              />
              min
            </span>
          </label>
        ))}
      </div>

      {save.isError && <p className="text-sm text-red-600" role="alert">Couldn’t save. Targets must be 1–10080 minutes.</p>}

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

export default function SlaPage() {
  const { data, isPending, isError } = useQuery({ queryKey: ["sla"], queryFn: fetchSla });

  return (
    <div className="max-w-2xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">SLA</h1>
        <p className="mt-1 text-sm text-zinc-500">Hold the team to first-response targets by ticket priority.</p>
      </div>

      {isPending && <p className="text-sm text-zinc-500" role="status">Loading…</p>}
      {isError && <p className="text-sm text-red-600" role="alert">Couldn’t load the SLA config.</p>}
      {data !== undefined && <SlaForm data={data} />}
    </div>
  );
}
