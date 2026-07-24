"use client";

import { useEffect, useState } from "react";

import { useTranslations } from "next-intl";

import { availabilitySchema } from "@/lib/api/schemas";

/**
 * Agent availability switch (routing v2, §16): toggles the caller's own
 * available/away state. Only `available` agents receive auto-assignments.
 * Optimistic — reverts on failure.
 */
export function AvailabilityToggle() {
  const t = useTranslations("availability");
  const [available, setAvailable] = useState<boolean | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let active = true;
    void fetch("/api/cp/me/availability", { cache: "no-store" })
      .then((r) => (r.ok ? r.json() : null))
      .then((json) => {
        if (!active || json === null) return;
        const parsed = availabilitySchema.safeParse(json);
        if (parsed.success) setAvailable(parsed.data.availability === "available");
      })
      .catch(() => {});
    return () => {
      active = false;
    };
  }, []);

  const toggle = async () => {
    if (available === null || busy) return;
    const next = !available;
    setAvailable(next);
    setBusy(true);
    try {
      const res = await fetch("/api/cp/me/availability", {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ availability: next ? "available" : "away" }),
      });
      if (!res.ok) setAvailable(!next); // revert
    } catch {
      setAvailable(!next);
    } finally {
      setBusy(false);
    }
  };

  if (available === null) return null;

  return (
    <button
      type="button"
      onClick={() => void toggle()}
      disabled={busy}
      aria-pressed={available}
      className="inline-flex items-center gap-1.5 rounded-md border border-zinc-300 px-2.5 py-1 text-xs font-medium hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-60 dark:border-zinc-700 dark:hover:bg-zinc-800"
    >
      <span
        aria-hidden
        className={`size-2 rounded-full ${available ? "bg-green-500" : "bg-zinc-400"}`}
      />
      {available ? t("available") : t("away")}
    </button>
  );
}
