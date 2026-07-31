"use client";

import Link from "next/link";
import { useActionState } from "react";

import { resetPassword, type ResetState } from "./actions";

const initialState: ResetState = { error: null, done: false };

const inputClass =
  "w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900";

export function ResetForm({
  organization,
  email,
  token,
}: {
  organization: string;
  email: string;
  token: string;
}) {
  const [state, formAction, pending] = useActionState(resetPassword, initialState);

  if (state.done) {
    return (
      <div className="space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
        <p role="status">Your password has been reset.</p>
        <Link
          href="/login"
          className="inline-block w-full rounded-md bg-indigo-600 px-3 py-2 text-center font-semibold text-white hover:bg-indigo-500"
        >
          Sign in with your new password
        </Link>
      </div>
    );
  }

  return (
    <form action={formAction} className="space-y-4" noValidate>
      <input type="hidden" name="organization" value={organization} />
      <input type="hidden" name="email" value={email} />
      <input type="hidden" name="token" value={token} />

      {state.error !== null && (
        <p
          role="alert"
          className="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200"
        >
          {state.error}
        </p>
      )}

      <p className="text-sm text-zinc-600 dark:text-zinc-400">
        Resetting the password for <span className="font-medium">{email}</span>.
      </p>

      <div className="space-y-1">
        <label htmlFor="password" className="block text-sm font-medium">
          New password
        </label>
        <input
          id="password"
          name="password"
          type="password"
          required
          minLength={8}
          autoComplete="new-password"
          className={inputClass}
        />
      </div>

      <div className="space-y-1">
        <label htmlFor="password_confirm" className="block text-sm font-medium">
          Confirm new password
        </label>
        <input
          id="password_confirm"
          name="password_confirm"
          type="password"
          required
          minLength={8}
          autoComplete="new-password"
          className={inputClass}
        />
      </div>

      <button
        type="submit"
        disabled={pending}
        className="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-60"
      >
        {pending ? "Resetting…" : "Reset password"}
      </button>
    </form>
  );
}
