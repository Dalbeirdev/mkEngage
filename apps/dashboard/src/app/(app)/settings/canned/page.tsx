"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import {
  cannedResponseListSchema,
  cannedResponseSchema,
  type CannedResponse,
} from "@/lib/api/schemas";
import { btnPrimary, btnSmall, cardPad, emptyState, input, pageTitle } from "@/lib/ui";

async function fetchCanned() {
  const response = await fetch("/api/cp/canned-responses", { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed to load (${response.status})`);
  return cannedResponseListSchema.parse(await response.json()).data;
}

async function createCanned(payload: { title: string; shortcut: string; body: string }) {
  const response = await fetch("/api/cp/canned-responses", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  if (!response.ok) throw new Error(`Create failed (${response.status})`);
  return cannedResponseSchema.parse(await response.json());
}

async function deleteCanned(id: string) {
  const response = await fetch(`/api/cp/canned-responses/${id}`, { method: "DELETE" });
  if (!response.ok) throw new Error(`Delete failed (${response.status})`);
}

export default function CannedResponsesPage() {
  const t = useTranslations("canned");
  const queryClient = useQueryClient();

  const [title, setTitle] = useState("");
  const [shortcut, setShortcut] = useState("");
  const [body, setBody] = useState("");

  const { data, isPending, isError } = useQuery({
    queryKey: ["canned-responses"],
    queryFn: fetchCanned,
  });

  const invalidate = () =>
    void queryClient.invalidateQueries({ queryKey: ["canned-responses"] });

  const create = useMutation({
    mutationFn: createCanned,
    onSuccess: () => {
      setTitle("");
      setShortcut("");
      setBody("");
      invalidate();
    },
  });

  const remove = useMutation({ mutationFn: deleteCanned, onSuccess: invalidate });

  return (
    <div className="max-w-2xl space-y-6">
      <h1 className={pageTitle}>{t("title")}</h1>
      <p className="text-sm text-zinc-500">{t("subtitle")}</p>

      <form
        className={`${cardPad} space-y-3`}
        onSubmit={(event) => {
          event.preventDefault();
          if (create.isPending) return;
          create.mutate({
            title: title.trim(),
            shortcut: shortcut.trim().toLowerCase(),
            body: body.trim(),
          });
        }}
      >
        <div className="flex flex-wrap gap-3">
          <input
            type="text"
            required
            maxLength={100}
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            placeholder={t("fieldTitle")}
            aria-label={t("fieldTitle")}
            className={`${input} flex-1`}
          />
          <div className="flex items-center gap-1">
            <span className="text-sm text-zinc-500">/</span>
            <input
              type="text"
              required
              maxLength={30}
              pattern="[a-z0-9][a-z0-9_\-]*"
              value={shortcut}
              onChange={(event) => setShortcut(event.target.value.toLowerCase())}
              placeholder={t("fieldShortcut")}
              aria-label={t("fieldShortcut")}
              className={`${input} w-36`}
            />
          </div>
        </div>
        <textarea
          required
          rows={3}
          maxLength={16000}
          value={body}
          onChange={(event) => setBody(event.target.value)}
          placeholder={t("fieldBody")}
          aria-label={t("fieldBody")}
          className={`${input} w-full`}
        />
        {create.isError && (
          <p className="text-sm text-red-600" role="alert">
            {t("createError")}
          </p>
        )}
        <button type="submit" disabled={create.isPending} className={btnPrimary}>
          {create.isPending ? t("saving") : t("add")}
        </button>
      </form>

      {isPending && (
        <p className="text-sm text-zinc-500" role="status">
          {t("loading")}
        </p>
      )}
      {isError && (
        <p className="text-sm text-red-600" role="alert">
          {t("error")}
        </p>
      )}
      {data !== undefined && data.length === 0 && <p className={emptyState}>{t("empty")}</p>}

      {data !== undefined && data.length > 0 && (
        <ul className={`${cardPad} divide-y divide-zinc-100 dark:divide-zinc-800`}>
          {data.map((canned: CannedResponse) => (
            <li key={canned.canned_response_id} className="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
              <code className="mt-0.5 shrink-0 rounded bg-zinc-100 px-1.5 py-0.5 text-xs dark:bg-zinc-800">
                /{canned.shortcut}
              </code>
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">{canned.title}</p>
                <p className="truncate text-xs text-zinc-500">{canned.body}</p>
              </div>
              <button
                type="button"
                onClick={() => remove.mutate(canned.canned_response_id)}
                className={btnSmall}
              >
                {t("delete")}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
