"use client";

import { useActionState } from "react";

import { signup, type SignupState } from "./actions";

const initialState: SignupState = { error: null };

const inputCls =
  "w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900";

/**
 * Self-serve signup (§3). Credentials go browser → Next.js server → control
 * plane; the token never reaches client JS. Progressive enhancement — works
 * without JS.
 */
export function SignupForm() {
  const [state, formAction, pending] = useActionState(signup, initialState);

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
        <label htmlFor="organization_name" className="block text-sm font-medium">Company name</label>
        <input id="organization_name" name="organization_name" type="text" required autoComplete="organization" aria-invalid={state.error !== null || undefined} className={inputCls} />
      </div>

      <div className="space-y-1">
        <label htmlFor="name" className="block text-sm font-medium">Your name</label>
        <input id="name" name="name" type="text" required autoComplete="name" aria-invalid={state.error !== null || undefined} className={inputCls} />
      </div>

      <div className="space-y-1">
        <label htmlFor="email" className="block text-sm font-medium">Work email</label>
        <input id="email" name="email" type="email" required autoComplete="email" aria-invalid={state.error !== null || undefined} className={inputCls} />
      </div>

      <div className="space-y-1">
        <label htmlFor="password" className="block text-sm font-medium">Password</label>
        <input id="password" name="password" type="password" required minLength={8} autoComplete="new-password" aria-invalid={state.error !== null || undefined} className={inputCls} />
        <p className="text-xs text-zinc-500">At least 8 characters.</p>
      </div>

      <button
        type="submit"
        disabled={pending}
        className="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-60"
      >
        {pending ? "Creating your workspace…" : "Create free account"}
      </button>
    </form>
  );
}
