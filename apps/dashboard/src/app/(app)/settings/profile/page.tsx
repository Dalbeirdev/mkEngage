import { redirect } from "next/navigation";

import { IconChannels, IconMessages, IconProfile, IconStar } from "@/components/icons";
import { ProfilePreferences } from "@/components/profile-preferences";
import { userSchema, type User } from "@/lib/api/schemas";
import { ApiError, apiJson } from "@/lib/api/server";
import { clearSessionToken } from "@/lib/auth/session";

const cardShell =
  "rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900";

function formatDate(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
}

/**
 * Account profile (§3 server-side authorization). A 401 means the token was
 * revoked/expired — clear the cookie and bounce to login.
 */
export default async function ProfilePage() {
  let user: User;
  try {
    user = await apiJson("/api/user", (data) => userSchema.parse(data));
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      await clearSessionToken();
      redirect("/login");
    }
    throw error;
  }

  const initial = user.name.trim().charAt(0).toUpperCase() || "?";
  const verified = user.email_verified_at !== null;

  const accountRows: Array<{ icon: React.ReactNode; label: string; value: React.ReactNode }> = [
    { icon: <IconProfile />, label: "Full name", value: user.name },
    {
      icon: <IconMessages />,
      label: "Email",
      value: (
        <span className="inline-flex items-center gap-2">
          {user.email}
          <span
            className={`rounded-full px-2 py-0.5 text-xs font-medium ${
              verified
                ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                : "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300"
            }`}
          >
            {verified ? "Verified" : "Unverified"}
          </span>
        </span>
      ),
    },
    { icon: <IconChannels />, label: "Organization ID", value: <span className="font-mono text-xs">{user.organization_id}</span> },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Profile</h1>
        <p className="mt-1 text-sm text-zinc-500">Manage your account details and preferences.</p>
      </div>

      {/* Hero */}
      <div className={`overflow-hidden ${cardShell}`}>
        <div className="h-20 bg-gradient-to-r from-indigo-500/15 to-fuchsia-500/15" aria-hidden />
        <div className="flex flex-wrap items-center gap-4 px-6 pb-6">
          <span className="-mt-8 grid size-20 shrink-0 place-items-center rounded-full border-4 border-white bg-indigo-600 text-2xl font-bold text-white dark:border-zinc-900" aria-hidden>
            {initial}
          </span>
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <h2 className="text-lg font-bold">{user.name}</h2>
              <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">
                {user.status === "active" ? "Active" : user.status}
              </span>
            </div>
            <p className="mt-0.5 text-sm text-zinc-500">{user.email}</p>
            <p className="mt-0.5 text-xs text-zinc-400">Joined {formatDate(user.created_at)}</p>
          </div>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Account information */}
        <section className={cardShell}>
          <h2 className="border-b border-zinc-100 px-5 py-4 text-sm font-semibold dark:border-zinc-800">
            Account information
          </h2>
          <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {accountRows.map((r) => (
              <li key={r.label} className="flex items-center gap-3 px-5 py-3.5">
                <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800" aria-hidden>
                  {r.icon}
                </span>
                <span className="text-sm font-medium">{r.label}</span>
                <span className="ms-auto min-w-0 truncate text-sm text-zinc-600 dark:text-zinc-300">{r.value}</span>
              </li>
            ))}
            <li className="flex items-center gap-3 px-5 py-3.5">
              <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300" aria-hidden>
                <IconStar />
              </span>
              <span className="text-sm font-medium">Status</span>
              <span className="ms-auto text-sm text-emerald-600">
                {user.status === "active" ? "Active — all systems running" : user.status}
              </span>
            </li>
          </ul>
        </section>

        {/* Preferences */}
        <section className={cardShell}>
          <h2 className="border-b border-zinc-100 px-5 py-4 text-sm font-semibold dark:border-zinc-800">
            Preferences
          </h2>
          <ProfilePreferences />
        </section>
      </div>
    </div>
  );
}
