"use client";

import { useCallback, useSyncExternalStore } from "react";
import { useTranslations } from "next-intl";

type Theme = "light" | "dark";

/** Reactive view of the <html> class list (set pre-paint by the root layout script). */
function subscribe(onChange: () => void): () => void {
  const observer = new MutationObserver(onChange);
  observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ["class"],
  });
  return () => observer.disconnect();
}

function getSnapshot(): Theme {
  return document.documentElement.classList.contains("dark") ? "dark" : "light";
}

function getServerSnapshot(): Theme {
  return "light";
}

/**
 * Light/dark toggle (§3). Class strategy on <html>; initial value comes from
 * the inline script in the root layout (no flash), user choice persists in
 * localStorage. Accessible: real button, pressed state, visible focus.
 */
export function ThemeToggle() {
  const t = useTranslations("shell");
  const theme = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);

  const toggle = useCallback(() => {
    const next: Theme = getSnapshot() === "dark" ? "light" : "dark";
    document.documentElement.classList.toggle("dark", next === "dark");
    window.localStorage.setItem("mk-theme", next);
  }, []);

  return (
    <button
      type="button"
      onClick={toggle}
      aria-label={t("toggleTheme")}
      aria-pressed={theme === "dark"}
      className="rounded-md border border-zinc-300 px-2 py-1 text-sm hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:hover:bg-zinc-800"
    >
      {theme === "dark" ? "🌙" : "☀️"}
    </button>
  );
}
