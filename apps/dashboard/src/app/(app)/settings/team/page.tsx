"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { z } from "zod";

const memberSchema = z.object({
  user_id: z.string(),
  name: z.string(),
  email: z.string(),
  status: z.string(),
});
type Member = z.infer<typeof memberSchema>;

const listSchema = z.object({ data: z.array(memberSchema) });

async function fetchTeam(): Promise<Member[]> {
  const res = await fetch("/api/cp/users", { cache: "no-store" });
  if (!res.ok) throw new Error(`Failed to load (${res.status})`);
  return listSchema.parse(await res.json()).data;
}

const panel =
  "space-y-4 rounded-2xl border border-zinc-200 bg-white shadow-sm dark:bg-zinc-900 p-5 dark:border-zinc-800";
const input =
  "w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950";
const smallBtn =
  "rounded-lg border border-zinc-200 px-2.5 py-1 text-xs font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800";

function InviteForm() {
  const qc = useQueryClient();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);

  const invite = useMutation({
    mutationFn: async () => {
      setError(null);
      const res = await fetch("/api/cp/users", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name, email }),
      });
      if (!res.ok) {
        const body = (await res.json().catch(() => ({}))) as { message?: string };
        throw new Error(body.message ?? `Invite failed (${res.status})`);
      }
    },
    onSuccess: () => {
      setName("");
      setEmail("");
      setSent(true);
      setTimeout(() => setSent(false), 3000);
      void qc.invalidateQueries({ queryKey: ["team"] });
    },
    onError: (e: Error) => setError(e.message),
  });

  return (
    <section className={panel} aria-labelledby="invite-h">
      <div>
        <h2 id="invite-h" className="font-semibold">Invite an agent</h2>
        <p className="text-sm text-zinc-500">
          They get an email with a link to set their password (valid 60 minutes — use
          &quot;Resend invite&quot; if it expires). Requires outbound email (SMTP) on the server.
        </p>
      </div>

      <form
        className="grid gap-3 sm:grid-cols-[1fr_1fr_auto]"
        onSubmit={(e) => {
          e.preventDefault();
          invite.mutate();
        }}
      >
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Full name"
          required
          aria-label="Name"
          className={input}
        />
        <input
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          placeholder="Work email"
          required
          aria-label="Email"
          className={input}
        />
        <button
          type="submit"
          disabled={invite.isPending}
          className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60"
        >
          {invite.isPending ? "Inviting…" : sent ? "Invited" : "Invite"}
        </button>
      </form>

      {error !== null && <p className="text-sm text-red-600" role="alert">{error}</p>}
    </section>
  );
}

function MemberRow({ member, selfId }: { member: Member; selfId: string | null }) {
  const qc = useQueryClient();
  const refresh = () => void qc.invalidateQueries({ queryKey: ["team"] });

  const setStatus = useMutation({
    mutationFn: async (status: string) => {
      const res = await fetch(`/api/cp/users/${member.user_id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ status }),
      });
      if (!res.ok) throw new Error(`Failed (${res.status})`);
    },
    onSuccess: refresh,
  });

  const resend = useMutation({
    mutationFn: async () => {
      const res = await fetch(`/api/cp/users/${member.user_id}/invite`, { method: "POST" });
      if (!res.ok) throw new Error(`Failed (${res.status})`);
    },
  });

  const isSelf = member.user_id === selfId;
  const active = member.status === "active";

  return (
    <li className="flex flex-wrap items-center justify-between gap-3 py-3">
      <div>
        <p className="text-sm font-medium">
          {member.name}
          {isSelf && <span className="ml-2 text-xs text-zinc-400">(you)</span>}
        </p>
        <p className="text-sm text-zinc-500">{member.email}</p>
      </div>
      <div className="flex items-center gap-2">
        <span
          className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
            active
              ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
              : "bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400"
          }`}
        >
          {active ? "Active" : "Disabled"}
        </span>
        {!isSelf && active && (
          <button type="button" onClick={() => resend.mutate()} disabled={resend.isPending} className={smallBtn}>
            {resend.isPending ? "Sending…" : resend.isSuccess ? "Sent" : "Resend invite"}
          </button>
        )}
        {!isSelf && (
          <button
            type="button"
            onClick={() => setStatus.mutate(active ? "disabled" : "active")}
            disabled={setStatus.isPending}
            className={smallBtn}
          >
            {active ? "Deactivate" : "Activate"}
          </button>
        )}
        {setStatus.isError && <span className="text-xs text-red-600" role="alert">Failed</span>}
      </div>
    </li>
  );
}

const profileSchema = z.object({ id: z.string() });

export default function TeamPage() {
  const team = useQuery({ queryKey: ["team"], queryFn: fetchTeam });
  const me = useQuery({
    queryKey: ["team", "me"],
    queryFn: async () => {
      const res = await fetch("/api/cp/user", { cache: "no-store" });
      if (!res.ok) throw new Error(`Failed (${res.status})`);
      return profileSchema.parse(await res.json());
    },
  });

  return (
    <div className="max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Team</h1>
        <p className="mt-1 text-sm text-zinc-500">
          Invite agents and manage who can sign in. Seats are limited by your plan — see Billing.
        </p>
      </div>

      <InviteForm />

      <section className={panel} aria-labelledby="members-h">
        <h2 id="members-h" className="font-semibold">Members</h2>
        {team.isPending && <p className="text-sm text-zinc-500" role="status">Loading…</p>}
        {team.isError && <p className="text-sm text-red-600" role="alert">Couldn&apos;t load the team.</p>}
        {team.data !== undefined && (
          <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {team.data.map((member) => (
              <MemberRow key={member.user_id} member={member} selfId={me.data?.id ?? null} />
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
