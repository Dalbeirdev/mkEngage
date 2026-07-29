"use client";

import { useActionState } from "react";
import { useTranslations } from "next-intl";

import { login, type LoginState } from "./actions";

const initialState: LoginState = { error: null };

/**
 * Server-action form (§3): credentials go browser → Next.js server → control
 * plane; the token never reaches client JS. Progressive enhancement — works
 * without JS. Field-level a11y: labels, autocomplete, aria-invalid wiring.
 */
export function LoginForm() {
  const t = useTranslations("login");
  const [state, formAction, pending] = useActionState(login, initialState);

  return (
    <form action={formAction} className="space-y-4" noValidate>
      {state.error !== null && (
        <p
          role="alert"
          className="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200"
        >
          {state.error}
        </p>
      )}

      <div className="space-y-1">
        <label htmlFor="organization" className="block text-sm font-medium">
          {t("organization")}
        </label>
        <input
          id="organization"
          name="organization"
          type="text"
          required
          autoComplete="organization"
          aria-invalid={state.error !== null || undefined}
          className="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
        />
      </div>

      <div className="space-y-1">
        <label htmlFor="email" className="block text-sm font-medium">
          {t("email")}
        </label>
        <input
          id="email"
          name="email"
          type="email"
          required
          autoComplete="email"
          aria-invalid={state.error !== null || undefined}
          className="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
        />
      </div>

      <div className="space-y-1">
        <label htmlFor="password" className="block text-sm font-medium">
          {t("password")}
        </label>
        <input
          id="password"
          name="password"
          type="password"
          required
          autoComplete="current-password"
          aria-invalid={state.error !== null || undefined}
          className="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
        />
      </div>

      {state.twoFactorRequired === true && (
        <div className="space-y-1">
          <label htmlFor="code" className="block text-sm font-medium">
            {t("twoFactorLabel")}
          </label>
          <input
            id="code"
            name="code"
            type="text"
            inputMode="numeric"
            autoComplete="one-time-code"
            autoFocus
            maxLength={10}
            className="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm tracking-widest outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
          />
          <p className="text-xs text-zinc-500">{t("twoFactorHint")}</p>
        </div>
      )}

      <button
        type="submit"
        disabled={pending}
        className="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-60"
      >
        {pending ? t("submitting") : t("submit")}
      </button>
    </form>
  );
}
