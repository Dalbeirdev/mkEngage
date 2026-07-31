"use client";

import { useActionState } from "react";

import { forgotPassword, type ForgotState } from "./actions";

const initialState: ForgotState = { error: null, sent: false };

const inputClass =
  "w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900";

export function ForgotForm() {
  const [state, formAction, pending] = useActionState(forgotPassword, initialState);

  if (state.sent) {
    return (
      <p role="status" className="text-sm text-zinc-700 dark:text-zinc-300">
        If that account exists, a reset link is on its way to your email. The
        link is valid for 60 minutes — check your spam folder too.
      </p>
    );
  }

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
          Organization
        </label>
        <input
          id="organization"
          name="organization"
          type="text"
          required
          autoComplete="organization"
          className={inputClass}
        />
      </div>

      <div className="space-y-1">
        <label htmlFor="email" className="block text-sm font-medium">
          Email
        </label>
        <input id="email" name="email" type="email" required autoComplete="email" className={inputClass} />
      </div>

      <button
        type="submit"
        disabled={pending}
        className="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-60"
      >
        {pending ? "Sending…" : "Send reset link"}
      </button>
    </form>
  );
}
