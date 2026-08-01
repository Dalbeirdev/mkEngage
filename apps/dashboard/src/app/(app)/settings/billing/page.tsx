"use client";

import { useSyncExternalStore } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { z } from "zod";

const planInfoSchema = z.object({
  label: z.string(),
  price: z.string(),
  max_channels: z.number().int().nullable(),
  max_chatbots: z.number().int().nullable(),
  max_agents: z.number().int().nullable(),
  white_label: z.boolean(),
});

const billingSchema = z.object({
  plan: z.string(),
  label: z.string(),
  price: z.string(),
  expires_at: z.string().nullable(),
  white_label: z.boolean(),
  limits: z.object({
    channels: z.number().int().nullable(),
    chatbots: z.number().int().nullable(),
    agents: z.number().int().nullable(),
  }),
  usage: z.object({
    channels: z.number().int(),
    chatbots: z.number().int(),
    agents: z.number().int(),
  }),
  catalog: z.record(z.string(), planInfoSchema),
  checkout_enabled: z.boolean(),
  checkout_plans: z.array(z.string()),
});
type Billing = z.infer<typeof billingSchema>;

async function fetchBilling(): Promise<Billing> {
  const res = await fetch("/api/cp/organization/billing", { cache: "no-store" });
  if (!res.ok) throw new Error(`Failed to load (${res.status})`);
  return billingSchema.parse(await res.json());
}

const panel =
  "space-y-4 rounded-2xl border border-zinc-200 bg-white shadow-sm dark:bg-zinc-900 p-5 dark:border-zinc-800";

function UsageRow({ label, used, max }: { label: string; used: number; max: number | null }) {
  const pct = max === null ? 0 : Math.min(100, Math.round((used / Math.max(1, max)) * 100));

  return (
    <div className="space-y-1">
      <div className="flex items-center justify-between text-sm">
        <span className="font-medium">{label}</span>
        <span className="text-zinc-500">
          {used} / {max === null ? "Unlimited" : max}
        </span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" aria-hidden>
        <div
          className={`h-full rounded-full ${pct >= 100 ? "bg-red-500" : "bg-indigo-500"}`}
          style={{ width: max === null ? "6%" : `${pct}%` }}
        />
      </div>
    </div>
  );
}

export default function BillingPage() {
  const { data, isPending, isError } = useQuery({ queryKey: ["billing"], queryFn: fetchBilling });
  // Hydration-safe read of ?checkout= (server snapshot: none).
  const checkoutResult = useSyncExternalStore(
    () => () => {},
    () => new URLSearchParams(window.location.search).get("checkout"),
    () => null,
  );

  const checkout = useMutation({
    mutationFn: async (plan: string) => {
      const res = await fetch("/api/cp/organization/billing/checkout", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ plan }),
      });
      if (!res.ok) throw new Error(`Checkout failed (${res.status})`);
      const { url } = z.object({ url: z.string() }).parse(await res.json());
      window.location.href = url;
    },
  });

  return (
    <div className="max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Billing</h1>
        <p className="mt-1 text-sm text-zinc-500">Your plan, limits, and usage.</p>
      </div>

      {checkoutResult === "success" && (
        <p role="status" className="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
          Payment received — your plan activates within a minute. Refresh to see it.
        </p>
      )}
      {checkoutResult === "cancelled" && (
        <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
          Checkout cancelled — your plan is unchanged.
        </p>
      )}

      {isPending && <p className="text-sm text-zinc-500" role="status">Loading…</p>}
      {isError && <p className="text-sm text-red-600" role="alert">Couldn&apos;t load billing.</p>}

      {data !== undefined && (
        <>
          <section className={panel} aria-labelledby="plan-h">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 id="plan-h" className="font-semibold">
                  Current plan:{" "}
                  <span className="rounded-full bg-indigo-50 px-2.5 py-0.5 text-sm font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">
                    {data.label}
                  </span>
                </h2>
                <p className="mt-1 text-sm text-zinc-500">
                  {data.price}
                  {data.expires_at !== null &&
                    ` · renews/expires ${new Date(data.expires_at).toLocaleDateString()}`}
                  {data.white_label && " · white-label widget"}
                </p>
              </div>
            </div>

            <UsageRow label="Agents" used={data.usage.agents} max={data.limits.agents} />
            <UsageRow label="Channels" used={data.usage.channels} max={data.limits.channels} />
            <UsageRow label="Chatbots" used={data.usage.chatbots} max={data.limits.chatbots} />
          </section>

          <section className={panel} aria-labelledby="plans-h">
            <h2 id="plans-h" className="font-semibold">Plans</h2>
            <div className="grid gap-4 sm:grid-cols-3">
              {Object.entries(data.catalog).map(([key, plan]) => (
                <div
                  key={key}
                  className={`rounded-xl border p-4 ${
                    key === data.plan
                      ? "border-indigo-400 ring-1 ring-indigo-400 dark:border-indigo-500"
                      : "border-zinc-200 dark:border-zinc-700"
                  }`}
                >
                  <p className="font-semibold">{plan.label}</p>
                  <p className="text-sm text-zinc-500">{plan.price}</p>
                  <ul className="mt-3 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                    <li>{plan.max_agents === null ? "Unlimited agents" : `${plan.max_agents} agents`}</li>
                    <li>{plan.max_channels === null ? "Unlimited channels" : `${plan.max_channels} channels`}</li>
                    <li>{plan.max_chatbots === null ? "Unlimited chatbots" : `${plan.max_chatbots} chatbots`}</li>
                    <li>{plan.white_label ? "White-label widget" : "mkEngage branding"}</li>
                  </ul>
                  {key === data.plan && (
                    <p className="mt-3 text-xs font-semibold text-indigo-600 dark:text-indigo-400">Your plan</p>
                  )}
                  {key !== data.plan && data.checkout_plans.includes(key) && (
                    <button
                      type="button"
                      disabled={checkout.isPending}
                      onClick={() => checkout.mutate(key)}
                      className="mt-3 w-full rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60"
                    >
                      {checkout.isPending ? "Redirecting…" : `Upgrade to ${plan.label}`}
                    </button>
                  )}
                </div>
              ))}
            </div>
            {checkout.isError && (
              <p className="text-sm text-red-600" role="alert">Couldn&apos;t start checkout. Try again.</p>
            )}
            <p className="text-sm text-zinc-500">
              {data.checkout_enabled
                ? "Pay by card via Stripe — your plan activates automatically. Prefer an invoice?"
                : "To upgrade, contact us at"}{" "}
              <a href="mailto:sales@mkengage.com" className="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                sales@mkengage.com
              </a>{" "}
              or +91 98887 72736 — your plan is activated the same day.
            </p>
          </section>
        </>
      )}
    </div>
  );
}
