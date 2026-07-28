"use client";

import Link from "next/link";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  IconChannels,
  IconClock,
  IconMessages,
  IconProfile,
  IconStar,
} from "@/components/icons";
import { ProfilePreferences } from "@/components/profile-preferences";
import { profileSchema, type Profile } from "@/lib/api/schemas";

const cardShell =
  "rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900";

async function fetchProfile(): Promise<Profile> {
  const res = await fetch("/api/cp/profile", { cache: "no-store" });
  if (!res.ok) throw new Error(`Profile failed (${res.status})`);
  return profileSchema.parse(await res.json());
}

function fmtDate(iso: string | null): string {
  if (iso === null) return "—";
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "—" : d.toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
}

function relative(iso: string | null): string {
  if (iso === null) return "";
  const d = new Date(iso).getTime();
  if (Number.isNaN(d)) return "";
  const m = Math.floor(Math.max(0, Date.now() - d) / 60000);
  if (m < 1) return "Just now";
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `Today, ${new Date(iso).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}`;
  return new Date(iso).toLocaleString("en-US", { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
}

function humanize(action: string): string {
  const s = action.replace(/[._]/g, " ").trim();
  return s.charAt(0).toUpperCase() + s.slice(1);
}

const dotColor = ["#22c55e", "#3b82f6", "#8b5cf6", "#22c55e", "#f59e0b", "#ec4899"];

export default function ProfilePage() {
  const qc = useQueryClient();
  const { data, isPending, isError } = useQuery({ queryKey: ["profile"], queryFn: fetchProfile });

  const [editing, setEditing] = useState(false);
  const [nameDraft, setNameDraft] = useState("");
  const [pwOpen, setPwOpen] = useState(false);
  const [pw, setPw] = useState({ current: "", next: "", confirm: "" });
  const [pwError, setPwError] = useState<string | null>(null);
  const [checkedAt, setCheckedAt] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: async (name: string) => {
      const res = await fetch("/api/cp/profile", {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name }),
      });
      if (!res.ok) throw new Error(`Update failed (${res.status})`);
    },
    onSuccess: () => {
      setEditing(false);
      void qc.invalidateQueries({ queryKey: ["profile"] });
    },
  });

  const changePw = useMutation({
    mutationFn: async () => {
      setPwError(null);
      const res = await fetch("/api/cp/profile/password", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ current_password: pw.current, new_password: pw.next, new_password_confirmation: pw.confirm }),
      });
      if (!res.ok) throw new Error(res.status === 422 ? "Your current password is incorrect, or the new one is too short (min 8)." : `Failed (${res.status})`);
    },
    onSuccess: () => {
      setPwOpen(false);
      setPw({ current: "", next: "", confirm: "" });
    },
    onError: (e: Error) => setPwError(e.message),
  });

  if (isPending) return <p className="text-sm text-zinc-500" role="status">Loading your profile…</p>;
  if (isError || data === undefined) return <p className="text-sm text-red-600" role="alert">Couldn’t load your profile.</p>;

  const initial = data.name.trim().charAt(0).toUpperCase() || "?";
  const verified = data.email_verified_at !== null;

  const accountRows: { icon: React.ReactNode; label: string; value: React.ReactNode }[] = [
    { icon: <IconProfile />, label: "Full Name", value: data.name },
    { icon: <IconMessages />, label: "Email", value: (
      <span className="inline-flex items-center gap-2">{data.email}
        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${verified ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300" : "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300"}`}>{verified ? "Verified" : "Unverified"}</span>
      </span>
    ) },
    { icon: <IconChannels />, label: "Organization", value: <span className="font-mono text-xs">{data.organization_id}</span> },
    { icon: <IconStar />, label: "Role", value: data.role ?? "Member" },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Profile</h1>
        <p className="mt-1 text-sm text-zinc-500">Manage your account details, preferences and security settings.</p>
      </div>

      {/* Hero */}
      <div className={`overflow-hidden ${cardShell}`}>
        <div className="h-20 bg-gradient-to-r from-indigo-500/15 to-fuchsia-500/15" aria-hidden />
        <div className="flex flex-wrap items-center gap-4 px-6 pb-6">
          <span className="relative -mt-8 grid size-20 shrink-0 place-items-center rounded-full border-4 border-white bg-indigo-600 text-2xl font-bold text-white dark:border-zinc-900" aria-hidden>
            {initial}
            <span className="absolute right-1 bottom-1 size-4 rounded-full border-2 border-white bg-emerald-500 dark:border-zinc-900" />
          </span>
          <div className="min-w-0 flex-1">
            {editing ? (
              <form
                className="flex flex-wrap items-center gap-2"
                onSubmit={(e) => { e.preventDefault(); if (nameDraft.trim()) save.mutate(nameDraft.trim()); }}
              >
                <input
                  value={nameDraft}
                  onChange={(e) => setNameDraft(e.target.value)}
                  aria-label="Full name"
                  className="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                />
                <button type="submit" disabled={save.isPending} className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60">{save.isPending ? "Saving…" : "Save"}</button>
                <button type="button" onClick={() => setEditing(false)} className="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm dark:border-zinc-700">Cancel</button>
              </form>
            ) : (
              <>
                <div className="flex items-center gap-2">
                  <h2 className="text-lg font-bold">{data.name}</h2>
                  {data.role !== null && <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">{data.role}</span>}
                </div>
                <p className="mt-0.5 text-sm text-zinc-500">{data.email}</p>
                <p className="mt-0.5 text-xs text-zinc-400">Joined {fmtDate(data.created_at)}</p>
              </>
            )}
          </div>
          {!editing && (
            <button
              type="button"
              onClick={() => { setNameDraft(data.name); setEditing(true); }}
              className="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium transition-colors hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
            >
              ✎ Edit Profile
            </button>
          )}
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
        <div className="space-y-6">
          {/* Account info */}
          <section className={cardShell}>
            <h2 className="border-b border-zinc-100 px-5 py-4 text-sm font-semibold dark:border-zinc-800">Account Information</h2>
            <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
              {accountRows.map((r) => (
                <li key={r.label} className="flex items-center gap-3 px-5 py-3.5">
                  <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800" aria-hidden>{r.icon}</span>
                  <span className="w-28 shrink-0 text-sm text-zinc-500">{r.label}</span>
                  <span className="min-w-0 truncate text-sm font-medium">{r.value}</span>
                </li>
              ))}
              <li className="flex items-start gap-3 px-5 py-3.5">
                <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300" aria-hidden><IconStar /></span>
                <span className="w-28 shrink-0 text-sm text-zinc-500">Status</span>
                <span className="min-w-0">
                  <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">{data.status === "active" ? "Active" : data.status}</span>
                  <p className="mt-1 text-xs text-zinc-500">Your account is active and all systems are running smoothly.</p>
                </span>
              </li>
              <li className="flex items-center gap-3 px-5 py-3.5">
                <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800" aria-hidden><IconClock /></span>
                <span className="w-28 shrink-0 text-sm text-zinc-500">Live Check</span>
                <span className="min-w-0 flex-1">
                  <span className="inline-flex items-center gap-1.5 text-sm text-emerald-600"><span className="size-1.5 rounded-full bg-emerald-500" />Online</span>
                  <p className="text-xs text-zinc-400">{checkedAt ? `Last checked ${relative(checkedAt).toLowerCase()}` : "Last checked just now"}</p>
                </span>
                <button
                  type="button"
                  onClick={() => { void qc.invalidateQueries({ queryKey: ["profile"] }); setCheckedAt(new Date().toISOString()); }}
                  className="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                >
                  ↻ Check Now
                </button>
              </li>
            </ul>
          </section>

          {/* Security */}
          <section className={cardShell}>
            <h2 className="border-b border-zinc-100 px-5 py-4 text-sm font-semibold dark:border-zinc-800">Security</h2>
            <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
              <li className="px-5 py-3.5">
                <div className="flex items-center gap-3">
                  <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800" aria-hidden>🔒</span>
                  <span className="text-sm font-medium">Password</span>
                  <span className="ms-auto text-sm text-zinc-400">••••••••</span>
                  <button type="button" onClick={() => { setPwOpen((v) => !v); setPwError(null); }} className="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Change</button>
                </div>
                {pwOpen && (
                  <form className="mt-3 grid gap-2 ps-12" onSubmit={(e) => { e.preventDefault(); changePw.mutate(); }}>
                    <input type="password" placeholder="Current password" value={pw.current} onChange={(e) => setPw({ ...pw, current: e.target.value })} className="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                    <input type="password" placeholder="New password (min 8)" value={pw.next} onChange={(e) => setPw({ ...pw, next: e.target.value })} className="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                    <input type="password" placeholder="Confirm new password" value={pw.confirm} onChange={(e) => setPw({ ...pw, confirm: e.target.value })} className="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                    {pwError !== null && <p className="text-xs text-red-600" role="alert">{pwError}</p>}
                    <div className="flex gap-2">
                      <button type="submit" disabled={changePw.isPending} className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60">{changePw.isPending ? "Saving…" : "Update password"}</button>
                      <button type="button" onClick={() => setPwOpen(false)} className="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm dark:border-zinc-700">Cancel</button>
                    </div>
                  </form>
                )}
              </li>
              <li className="flex items-center gap-3 px-5 py-3.5">
                <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800" aria-hidden>🛡️</span>
                <span className="text-sm font-medium">Two-Factor Authentication</span>
                <span className="ms-auto text-sm text-zinc-400">Not enabled</span>
                <span className="rounded-lg border border-dashed border-zinc-300 px-3 py-1.5 text-xs text-zinc-400 dark:border-zinc-700" title="Coming soon">Coming soon</span>
              </li>
              <li className="flex items-center gap-3 px-5 py-3.5">
                <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800" aria-hidden>💻</span>
                <span className="text-sm font-medium">Active Sessions</span>
                <span className="ms-auto text-sm text-zinc-500">{data.active_sessions} active session{data.active_sessions === 1 ? "" : "s"}</span>
              </li>
            </ul>
          </section>
        </div>

        <div className="space-y-6">
          {/* Activity */}
          <section className={cardShell}>
            <div className="flex items-center justify-between border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
              <h2 className="text-sm font-semibold">Activity Overview</h2>
              <span className="text-sm text-zinc-400">Last {data.activity.length}</span>
            </div>
            {data.activity.length === 0 ? (
              <p className="px-5 py-6 text-sm text-zinc-500">No recent activity.</p>
            ) : (
              <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
                {data.activity.map((a, i) => (
                  <li key={i} className="flex items-center gap-3 px-5 py-3.5">
                    <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800" aria-hidden><IconClock /></span>
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-medium">{humanize(a.action)}</p>
                      <p className="text-xs text-zinc-400">{relative(a.at)}</p>
                    </div>
                    <span className="size-2 rounded-full" style={{ background: dotColor[i % dotColor.length] }} aria-hidden />
                  </li>
                ))}
              </ul>
            )}
          </section>

          {/* Preferences */}
          <section className={cardShell}>
            <h2 className="border-b border-zinc-100 px-5 py-4 text-sm font-semibold dark:border-zinc-800">Preferences</h2>
            <ProfilePreferences />
          </section>
        </div>
      </div>

      <div className="border-t border-zinc-100 pt-4 text-center text-xs text-zinc-400 dark:border-zinc-800">
        © 2026 mkEngage. All rights reserved. ·{" "}
        <Link href="/legal/privacy" className="hover:text-zinc-600">Privacy Policy</Link> ·{" "}
        <Link href="/legal/terms" className="hover:text-zinc-600">Terms of Service</Link>
      </div>
    </div>
  );
}
