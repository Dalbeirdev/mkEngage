"use client";

import { useSyncExternalStore } from "react";

/**
 * Time-of-day greeting. The salutation depends on the viewer's local hour, so
 * it's read via useSyncExternalStore: the server snapshot is neutral ("Hello")
 * and the client snapshot resolves to the local time-of-day — no setState in an
 * effect, and no hydration mismatch warning.
 */
const subscribe = () => () => {};

function localSalutation(): string {
  const h = new Date().getHours();
  return h < 12 ? "Good morning" : h < 18 ? "Good afternoon" : "Good evening";
}

export function Greeting({ name }: { name: string }) {
  const salutation = useSyncExternalStore(subscribe, localSalutation, () => "Hello");

  return (
    <div>
      <h1 className="text-2xl font-bold tracking-tight">
        <span aria-hidden>👋</span> {salutation}, {name}!
      </h1>
      <p className="mt-1 text-sm text-zinc-500">
        Here’s what’s happening with your conversations today.
      </p>
    </div>
  );
}
