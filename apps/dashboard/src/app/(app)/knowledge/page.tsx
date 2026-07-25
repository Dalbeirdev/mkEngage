"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { knowledgeListSchema } from "@/lib/api/schemas";

async function fetchDocuments() {
  const response = await fetch("/api/cp/knowledge/documents", { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed (${response.status})`);
  return knowledgeListSchema.parse(await response.json()).data;
}

const statusStyles: Record<string, string> = {
  ready: "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300",
  pending: "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300",
  failed: "bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300",
};

export default function KnowledgePage() {
  const t = useTranslations("knowledge");
  const queryClient = useQueryClient();
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");

  const documents = useQuery({
    queryKey: ["knowledge"],
    queryFn: fetchDocuments,
    refetchInterval: 10000,
  });

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: ["knowledge"] });

  const create = useMutation({
    mutationFn: async () => {
      const response = await fetch("/api/cp/knowledge/documents", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ title: title.trim(), body: body.trim() }),
      });
      if (!response.ok) throw new Error(`Create failed (${response.status})`);
    },
    onSuccess: () => {
      setTitle("");
      setBody("");
      invalidate();
    },
  });

  const destroy = useMutation({
    mutationFn: async (documentId: string) => {
      const response = await fetch(`/api/cp/knowledge/documents/${documentId}`, {
        method: "DELETE",
      });
      if (!response.ok) throw new Error(`Delete failed (${response.status})`);
    },
    onSuccess: invalidate,
  });

  const inputClass =
    "w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900";

  return (
    <div className="max-w-2xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-zinc-500">{t("subtitle")}</p>
      </div>

      <form
        onSubmit={(event) => {
          event.preventDefault();
          if (title.trim() && body.trim() && !create.isPending) create.mutate();
        }}
        className="space-y-3 rounded-2xl border border-zinc-200 bg-white shadow-sm dark:bg-zinc-900 p-4 dark:border-zinc-800"
        aria-label={t("createTitle")}
      >
        <div className="space-y-1">
          <label htmlFor="doc-title" className="block text-sm font-medium">
            {t("titleLabel")}
          </label>
          <input
            id="doc-title"
            value={title}
            maxLength={200}
            onChange={(event) => setTitle(event.target.value)}
            className={inputClass}
          />
        </div>
        <div className="space-y-1">
          <label htmlFor="doc-body" className="block text-sm font-medium">
            {t("bodyLabel")}
          </label>
          <textarea
            id="doc-body"
            rows={6}
            maxLength={100000}
            value={body}
            placeholder={t("bodyPlaceholder")}
            onChange={(event) => setBody(event.target.value)}
            className={inputClass}
          />
        </div>
        <button
          type="submit"
          disabled={create.isPending || !title.trim() || !body.trim()}
          className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-60"
        >
          {create.isPending ? t("adding") : t("add")}
        </button>
      </form>

      {documents.isPending && (
        <p className="text-sm text-zinc-500" role="status">
          {t("loading")}
        </p>
      )}
      {documents.isError && (
        <p className="text-sm text-red-600" role="alert">
          {t("error")}
        </p>
      )}

      {documents.data !== undefined && documents.data.length === 0 && (
        <div className="rounded-xl border border-dashed border-zinc-300 p-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
          {t("empty")}
        </div>
      )}

      {documents.data !== undefined && documents.data.length > 0 && (
        <ul className="divide-y divide-zinc-200 rounded-2xl border border-zinc-200 bg-white shadow-sm dark:bg-zinc-900 dark:divide-zinc-800 dark:border-zinc-800">
          {documents.data.map((doc) => (
            <li
              key={doc.document_id}
              className="flex items-center justify-between gap-3 px-4 py-3 text-sm"
            >
              <span className="min-w-0">
                <span className="block truncate font-medium">{doc.title}</span>
                <span className="text-xs text-zinc-500">
                  {t("chunks", { count: doc.chunk_count })}
                </span>
              </span>
              <span className="flex shrink-0 items-center gap-2">
                <span
                  className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusStyles[doc.status]}`}
                >
                  {t(`status_${doc.status}`)}
                </span>
                <button
                  type="button"
                  onClick={() => {
                    if (window.confirm(t("deleteConfirm"))) destroy.mutate(doc.document_id);
                  }}
                  disabled={destroy.isPending}
                  className="rounded-md border border-zinc-300 px-3 py-1 text-xs hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:hover:bg-zinc-800"
                >
                  {t("delete")}
                </button>
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
