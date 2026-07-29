"use client";

import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { z } from "zod";

const enrollSchema = z.object({
  secret: z.string(),
  otpauth_uri: z.string(),
  qr_svg: z.string(),
});
const codesSchema = z.object({ recovery_codes: z.array(z.string()) });

type Enrollment = z.infer<typeof enrollSchema>;

const btnPrimary =
  "rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60";
const btnGhost =
  "rounded-lg border border-zinc-200 px-3 py-1.5 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800";
const field =
  "rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900";

/** Recovery codes shown once — user must copy/print them before continuing. */
function RecoveryCodes({ codes, onDone }: { codes: string[]; onDone: () => void }) {
  const [copied, setCopied] = useState(false);
  return (
    <div className="mt-3 space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950">
      <p className="text-sm font-semibold text-amber-900 dark:text-amber-200">
        Save your recovery codes
      </p>
      <p className="text-xs text-amber-800 dark:text-amber-300">
        Each code works once if you lose your authenticator. They won’t be shown again.
      </p>
      <ul className="grid grid-cols-2 gap-1 font-mono text-xs">
        {codes.map((code) => (
          <li key={code} className="rounded bg-white px-2 py-1 dark:bg-zinc-900">
            {code}
          </li>
        ))}
      </ul>
      <div className="flex gap-2">
        <button
          type="button"
          className={btnGhost}
          onClick={() => {
            void navigator.clipboard.writeText(codes.join("\n")).then(() => {
              setCopied(true);
              setTimeout(() => setCopied(false), 2000);
            });
          }}
        >
          {copied ? "Copied" : "Copy codes"}
        </button>
        <button type="button" className={btnPrimary} onClick={onDone}>
          Done
        </button>
      </div>
    </div>
  );
}

export function TwoFactor({ enabled }: { enabled: boolean }) {
  const qc = useQueryClient();
  const [enrollment, setEnrollment] = useState<Enrollment | null>(null);
  const [code, setCode] = useState("");
  const [newCodes, setNewCodes] = useState<string[] | null>(null);
  const [disabling, setDisabling] = useState(false);
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);

  const refresh = () => void qc.invalidateQueries({ queryKey: ["profile"] });

  const enroll = useMutation({
    mutationFn: async (): Promise<Enrollment> => {
      const res = await fetch("/api/cp/profile/2fa/enroll", { method: "POST" });
      if (!res.ok) throw new Error(`Enroll failed (${res.status})`);
      return enrollSchema.parse(await res.json());
    },
    onSuccess: (data) => {
      setError(null);
      setEnrollment(data);
    },
  });

  const confirm = useMutation({
    mutationFn: async (): Promise<string[]> => {
      setError(null);
      const res = await fetch("/api/cp/profile/2fa/confirm", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ code: code.trim() }),
      });
      if (!res.ok) throw new Error("That code is not valid. Check your app and try again.");
      return codesSchema.parse(await res.json()).recovery_codes;
    },
    onSuccess: (codes) => {
      setEnrollment(null);
      setCode("");
      setNewCodes(codes);
      refresh();
    },
    onError: (e: Error) => setError(e.message),
  });

  const disable = useMutation({
    mutationFn: async () => {
      setError(null);
      const res = await fetch("/api/cp/profile/2fa", {
        method: "DELETE",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ password }),
      });
      if (!res.ok) throw new Error("Your password is incorrect.");
    },
    onSuccess: () => {
      setDisabling(false);
      setPassword("");
      refresh();
    },
    onError: (e: Error) => setError(e.message),
  });

  const regenerate = useMutation({
    mutationFn: async (): Promise<string[]> => {
      setError(null);
      const res = await fetch("/api/cp/profile/2fa/recovery-codes", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ password }),
      });
      if (!res.ok) throw new Error("Your password is incorrect.");
      return codesSchema.parse(await res.json()).recovery_codes;
    },
    onSuccess: (codes) => {
      setDisabling(false);
      setPassword("");
      setNewCodes(codes);
    },
    onError: (e: Error) => setError(e.message),
  });

  // Freshly-issued recovery codes take over the row until acknowledged.
  if (newCodes !== null) {
    return (
      <li className="px-5 py-3.5">
        <div className="flex items-center gap-3">
          <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300" aria-hidden>
            🛡️
          </span>
          <span className="text-sm font-medium">Two-Factor Authentication</span>
        </div>
        <div className="ps-12">
          <RecoveryCodes codes={newCodes} onDone={() => setNewCodes(null)} />
        </div>
      </li>
    );
  }

  return (
    <li className="px-5 py-3.5">
      <div className="flex items-center gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800" aria-hidden>
          🛡️
        </span>
        <span className="text-sm font-medium">Two-Factor Authentication</span>
        {enabled ? (
          <span className="ms-auto rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
            Enabled
          </span>
        ) : (
          <span className="ms-auto text-sm text-zinc-400">Not enabled</span>
        )}
        {enabled ? (
          <button type="button" className={btnGhost} onClick={() => { setDisabling((v) => !v); setError(null); }}>
            Manage
          </button>
        ) : enrollment === null ? (
          <button type="button" className={btnPrimary} disabled={enroll.isPending} onClick={() => enroll.mutate()}>
            {enroll.isPending ? "Starting…" : "Enable"}
          </button>
        ) : null}
      </div>

      {/* Enrollment: scan the QR, then confirm a code. */}
      {!enabled && enrollment !== null && (
        <div className="mt-3 space-y-3 ps-12">
          <p className="text-sm text-zinc-600 dark:text-zinc-300">
            Scan this with Google Authenticator, Authy, or 1Password — or enter the key manually.
          </p>
          <div className="flex flex-wrap items-center gap-4">
            {/* Server-rendered SVG QR, embedded as a data URI (no external calls).
                next/image can't optimize an inline data URI, so a plain img is correct. */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={`data:image/svg+xml,${encodeURIComponent(enrollment.qr_svg)}`}
              alt="Two-factor QR code"
              width={160}
              height={160}
              className="rounded-lg border border-zinc-200 bg-white p-2 dark:border-zinc-700"
            />
            <div className="space-y-1">
              <p className="text-xs text-zinc-500">Manual entry key</p>
              <code className="block break-all rounded bg-zinc-100 px-2 py-1 font-mono text-xs dark:bg-zinc-800">
                {enrollment.secret}
              </code>
            </div>
          </div>
          <form
            className="flex flex-wrap items-center gap-2"
            onSubmit={(e) => { e.preventDefault(); if (code.trim().length >= 6) confirm.mutate(); }}
          >
            <input
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))}
              inputMode="numeric"
              autoComplete="one-time-code"
              placeholder="6-digit code"
              aria-label="Authentication code"
              className={`${field} w-32 font-mono tracking-widest`}
            />
            <button type="submit" disabled={confirm.isPending} className={btnPrimary}>
              {confirm.isPending ? "Verifying…" : "Verify & enable"}
            </button>
            <button type="button" className={btnGhost} onClick={() => { setEnrollment(null); setCode(""); setError(null); }}>
              Cancel
            </button>
          </form>
          {error !== null && <p className="text-xs text-red-600" role="alert">{error}</p>}
        </div>
      )}

      {/* Manage an enabled factor: regenerate codes or disable (password-gated). */}
      {enabled && disabling && (
        <div className="mt-3 space-y-2 ps-12">
          <p className="text-sm text-zinc-600 dark:text-zinc-300">
            Confirm your password to regenerate recovery codes or turn off two-factor.
          </p>
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="Current password"
            aria-label="Current password"
            className={`${field} w-full max-w-xs`}
          />
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              disabled={regenerate.isPending || password === ""}
              className={btnGhost}
              onClick={() => regenerate.mutate()}
            >
              {regenerate.isPending ? "Working…" : "Regenerate recovery codes"}
            </button>
            <button
              type="button"
              disabled={disable.isPending || password === ""}
              className="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-500 disabled:opacity-60"
              onClick={() => disable.mutate()}
            >
              {disable.isPending ? "Disabling…" : "Disable 2FA"}
            </button>
          </div>
          {error !== null && <p className="text-xs text-red-600" role="alert">{error}</p>}
        </div>
      )}
    </li>
  );
}
