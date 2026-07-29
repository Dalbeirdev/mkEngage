"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { contactNoteListSchema, type Contact } from "@/lib/api/schemas";

async function fetchNotes(contactId: string) {
  const res = await fetch(`/api/cp/contacts/${contactId}/notes`, { cache: "no-store" });
  if (!res.ok) throw new Error(`Failed to load notes (${res.status})`);
  return contactNoteListSchema.parse(await res.json()).data;
}

function relative(iso: string | null): string {
  if (iso === null) return "";
  const d = new Date(iso).getTime();
  if (Number.isNaN(d)) return "";
  const m = Math.floor(Math.max(0, Date.now() - d) / 60000);
  if (m < 1) return "just now";
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h ago`;
  return new Date(iso).toLocaleDateString();
}

/** Slide-over showing a contact's details + agent notes (list + add). */
export function ContactDrawer({ contact, onClose }: { contact: Contact | null; onClose: () => void }) {
  const qc = useQueryClient();
  const [body, setBody] = useState("");

  const id = contact?.contact_id ?? "";
  const notes = useQuery({
    queryKey: ["contact-notes", id],
    queryFn: () => fetchNotes(id),
    enabled: contact !== null,
  });

  const addNote = useMutation({
    mutationFn: async () => {
      const res = await fetch(`/api/cp/contacts/${id}/notes`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ body: body.trim() }),
      });
      if (!res.ok) throw new Error(`Add note failed (${res.status})`);
    },
    onSuccess: () => {
      setBody("");
      void qc.invalidateQueries({ queryKey: ["contact-notes", id] });
    },
  });

  if (contact === null) return null;

  const name = contact.name ?? "Unnamed contact";
  const rows: [string, string][] = [
    ["Email", contact.email ?? "—"],
    ["Phone", contact.phone ?? "—"],
    ["External ID", contact.external_id ?? "—"],
    ["Created", contact.created_at === null ? "—" : new Date(contact.created_at).toLocaleString()],
  ];

  return (
    <div className="fixed inset-0 z-40 flex justify-end" role="dialog" aria-modal="true" aria-label={`Contact ${name}`}>
      <button type="button" aria-label="Close" className="absolute inset-0 bg-black/30" onClick={onClose} />
      <aside className="relative flex h-full w-full max-w-md flex-col overflow-y-auto bg-white shadow-xl dark:bg-zinc-900">
        <div className="flex items-center gap-3 border-b border-zinc-100 p-5 dark:border-zinc-800">
          <span aria-hidden className="grid size-11 shrink-0 place-items-center rounded-full bg-indigo-100 text-lg font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">
            {name.charAt(0).toUpperCase()}
          </span>
          <div className="min-w-0 flex-1">
            <h2 className="truncate text-lg font-bold">{name}</h2>
            <p className="truncate text-sm text-zinc-500">{contact.email ?? contact.external_id ?? "No contact details"}</p>
          </div>
          <button type="button" onClick={onClose} className="rounded-lg border border-zinc-200 px-2.5 py-1 text-sm hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
            Close
          </button>
        </div>

        <dl className="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-2 border-b border-zinc-100 p-5 text-sm dark:border-zinc-800">
          {rows.map(([k, v]) => (
            <div key={k} className="contents">
              <dt className="text-zinc-500">{k}</dt>
              <dd className="min-w-0 truncate font-medium">{v}</dd>
            </div>
          ))}
        </dl>

        <div className="flex-1 p-5">
          <h3 className="mb-3 text-sm font-semibold">Notes</h3>
          <form
            className="mb-4 space-y-2"
            onSubmit={(e) => { e.preventDefault(); if (body.trim() !== "" && !addNote.isPending) addNote.mutate(); }}
          >
            <textarea
              value={body}
              onChange={(e) => setBody(e.target.value)}
              rows={3}
              maxLength={8000}
              placeholder="Add an internal note about this contact…"
              aria-label="New note"
              className="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950"
            />
            <button type="submit" disabled={addNote.isPending || body.trim() === ""} className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60">
              {addNote.isPending ? "Saving…" : "Add note"}
            </button>
            {addNote.isError && <p className="text-xs text-red-600" role="alert">Couldn’t save the note.</p>}
          </form>

          {notes.isPending && <p className="text-sm text-zinc-500" role="status">Loading notes…</p>}
          {notes.data !== undefined && notes.data.length === 0 && (
            <p className="text-sm text-zinc-500">No notes yet.</p>
          )}
          <ul className="space-y-3">
            {(notes.data ?? []).map((n) => (
              <li key={n.note_id} className="rounded-lg border border-zinc-100 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                <p className="whitespace-pre-wrap text-sm">{n.body}</p>
                <p className="mt-1.5 text-xs text-zinc-400">
                  {n.author_name ?? "Agent"} · {relative(n.created_at)}
                </p>
              </li>
            ))}
          </ul>
        </div>
      </aside>
    </div>
  );
}
