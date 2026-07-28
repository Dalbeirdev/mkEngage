"use client";

import { useSyncExternalStore } from "react";

import { IconChannels, IconClock, IconMessages, IconSettings } from "@/components/icons";
import { ThemeToggle } from "@/components/theme-toggle";

const subscribe = () => () => {};

function localTimeZone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone;
  } catch {
    return "—";
  }
}

/**
 * Preferences the app can actually honour today: display language, the
 * viewer's detected time zone, and the working theme toggle. Read via
 * useSyncExternalStore so the client-only time zone doesn't cause a hydration
 * mismatch.
 */
export function ProfilePreferences() {
  const timeZone = useSyncExternalStore(subscribe, localTimeZone, () => "—");

  const rows = [
    { icon: <IconChannels />, label: "Language", value: "English (US)" },
    { icon: <IconClock />, label: "Time zone", value: timeZone },
    { icon: <IconMessages />, label: "Email notifications", value: "All enabled" },
  ];

  return (
    <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
      {rows.map((r) => (
        <li key={r.label} className="flex items-center gap-3 px-5 py-3.5">
          <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800" aria-hidden>
            {r.icon}
          </span>
          <span className="text-sm font-medium">{r.label}</span>
          <span className="ms-auto text-sm text-zinc-500">{r.value}</span>
        </li>
      ))}
      <li className="flex items-center gap-3 px-5 py-3.5">
        <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800" aria-hidden>
          <IconSettings />
        </span>
        <span className="text-sm font-medium">Theme</span>
        <span className="ms-auto">
          <ThemeToggle />
        </span>
      </li>
    </ul>
  );
}
