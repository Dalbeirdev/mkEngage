"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import {
  departmentListSchema,
  departmentSchema,
  userListSchema,
  type Department,
} from "@/lib/api/schemas";

async function fetchDepartments() {
  const response = await fetch("/api/cp/departments", { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed (${response.status})`);
  return departmentListSchema.parse(await response.json()).data;
}

async function fetchUsers() {
  const response = await fetch("/api/cp/users", { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed (${response.status})`);
  return userListSchema.parse(await response.json()).data;
}

export default function DepartmentsPage() {
  const t = useTranslations("departments");
  const queryClient = useQueryClient();
  const [name, setName] = useState("");
  const [editing, setEditing] = useState<Department | null>(null);

  const departments = useQuery({ queryKey: ["departments"], queryFn: fetchDepartments });
  const users = useQuery({ queryKey: ["users"], queryFn: fetchUsers });

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: ["departments"] });

  const create = useMutation({
    mutationFn: async (departmentName: string) => {
      const response = await fetch("/api/cp/departments", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name: departmentName }),
      });
      if (!response.ok) throw new Error(`Create failed (${response.status})`);
      return departmentSchema.parse(await response.json());
    },
    onSuccess: () => {
      setName("");
      invalidate();
    },
  });

  const makeDefault = useMutation({
    mutationFn: async (departmentId: string) => {
      const response = await fetch(`/api/cp/departments/${departmentId}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ is_default: true }),
      });
      if (!response.ok) throw new Error(`Update failed (${response.status})`);
    },
    onSuccess: invalidate,
  });

  const setStrategy = useMutation({
    mutationFn: async (vars: { id: string; strategy: string }) => {
      const response = await fetch(`/api/cp/departments/${vars.id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ assignment_strategy: vars.strategy }),
      });
      if (!response.ok) throw new Error(`Update failed (${response.status})`);
    },
    onSuccess: invalidate,
  });

  return (
    <div className="max-w-2xl space-y-6">
      <h1 className="text-2xl font-bold tracking-tight">{t("title")}</h1>
      <p className="text-sm text-zinc-500">{t("defaultNote")}</p>

      <form
        onSubmit={(event) => {
          event.preventDefault();
          if (name.trim().length > 0 && !create.isPending) create.mutate(name.trim());
        }}
        className="flex items-end gap-2"
        aria-label={t("createTitle")}
      >
        <div className="flex-1 space-y-1">
          <label htmlFor="dept-name" className="block text-sm font-medium">
            {t("nameLabel")}
          </label>
          <input
            id="dept-name"
            value={name}
            maxLength={100}
            onChange={(event) => setName(event.target.value)}
            className="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
          />
        </div>
        <button
          type="submit"
          disabled={create.isPending || name.trim().length === 0}
          className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-60"
        >
          {create.isPending ? t("creating") : t("create")}
        </button>
      </form>

      {departments.isPending && (
        <p className="text-sm text-zinc-500" role="status">
          {t("loading")}
        </p>
      )}
      {departments.isError && (
        <p className="text-sm text-red-600" role="alert">
          {t("error")}
        </p>
      )}

      {departments.data !== undefined && departments.data.length === 0 && (
        <div className="rounded-xl border border-dashed border-zinc-300 p-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
          {t("empty")}
        </div>
      )}

      {departments.data !== undefined && departments.data.length > 0 && (
        <ul className="divide-y divide-zinc-200 rounded-2xl border border-zinc-200 bg-white shadow-sm dark:bg-zinc-900 dark:divide-zinc-800 dark:border-zinc-800">
          {departments.data.map((department) => (
            <li
              key={department.department_id}
              className="flex items-center justify-between gap-3 px-4 py-3 text-sm"
            >
              <span className="min-w-0">
                <span className="block truncate font-medium">
                  {department.name}
                  {department.is_default && (
                    <span className="ms-2 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300">
                      {t("default")}
                    </span>
                  )}
                </span>
                <span className="text-xs text-zinc-500">
                  {t("members", { count: department.member_count })}
                </span>
              </span>
              <span className="flex shrink-0 items-center gap-2">
                <label className="sr-only" htmlFor={`strategy-${department.department_id}`}>
                  {t("strategyLabel")}
                </label>
                <select
                  id={`strategy-${department.department_id}`}
                  value={department.assignment_strategy}
                  disabled={setStrategy.isPending}
                  onChange={(event) =>
                    setStrategy.mutate({ id: department.department_id, strategy: event.target.value })
                  }
                  className="rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
                >
                  <option value="least_busy">{t("strategyLeastBusy")}</option>
                  <option value="round_robin">{t("strategyRoundRobin")}</option>
                  <option value="manual">{t("strategyManual")}</option>
                </select>
                {!department.is_default && (
                  <button
                    type="button"
                    onClick={() => makeDefault.mutate(department.department_id)}
                    disabled={makeDefault.isPending}
                    className="rounded-md border border-zinc-300 px-3 py-1 text-xs hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:hover:bg-zinc-800"
                  >
                    {t("makeDefault")}
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => setEditing(department)}
                  className="rounded-md border border-zinc-300 px-3 py-1 text-xs hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:hover:bg-zinc-800"
                >
                  {t("editMembers")}
                </button>
              </span>
            </li>
          ))}
        </ul>
      )}

      {editing !== null && users.data !== undefined && (
        <MemberEditor
          key={editing.department_id}
          department={editing}
          users={users.data}
          onClose={() => {
            setEditing(null);
            invalidate();
          }}
        />
      )}
    </div>
  );
}

function MemberEditor({
  department,
  users,
  onClose,
}: {
  department: Department;
  users: Array<{ user_id: string; name: string; email: string }>;
  onClose: () => void;
}) {
  const t = useTranslations("departments");
  // Initialized from the server contract; the component is keyed by
  // department_id so switching departments re-initializes.
  const [selected, setSelected] = useState<Set<string>>(
    () => new Set(department.member_ids),
  );

  const save = useMutation({
    mutationFn: async () => {
      const response = await fetch(`/api/cp/departments/${department.department_id}/members`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ user_ids: [...selected] }),
      });
      if (!response.ok) throw new Error(`Save failed (${response.status})`);
    },
    onSuccess: onClose,
  });

  return (
    <section
      aria-label={t("membersOf", { name: department.name })}
      className="space-y-3 rounded-xl border border-indigo-200 p-4 dark:border-indigo-900"
    >
      <h2 className="font-semibold">{t("membersOf", { name: department.name })}</h2>
      <ul className="max-h-56 space-y-1 overflow-y-auto">
        {users.map((user) => (
          <li key={user.user_id}>
            <label className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-900">
              <input
                type="checkbox"
                checked={selected.has(user.user_id)}
                onChange={(event) => {
                  const next = new Set(selected);
                  if (event.target.checked) next.add(user.user_id);
                  else next.delete(user.user_id);
                  setSelected(next);
                }}
                className="size-4 accent-indigo-600"
              />
              <span className="min-w-0 flex-1 truncate">{user.name}</span>
              <span className="truncate text-xs text-zinc-500">{user.email}</span>
            </label>
          </li>
        ))}
      </ul>
      <div className="flex gap-2">
        <button
          type="button"
          onClick={() => save.mutate()}
          disabled={save.isPending}
          className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-60"
        >
          {save.isPending ? t("saving") : t("save")}
        </button>
        <button
          type="button"
          onClick={onClose}
          className="rounded-md border border-zinc-300 px-4 py-2 text-sm hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:hover:bg-zinc-800"
        >
          {t("close")}
        </button>
      </div>
    </section>
  );
}
