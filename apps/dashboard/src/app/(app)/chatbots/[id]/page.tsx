"use client";

import { use, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { chatbotSchema, type ChatbotConfig } from "@/lib/api/schemas";

async function fetchChatbot(id: string) {
  const response = await fetch(`/api/cp/chatbots/${id}`, { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed to load chatbot (${response.status})`);
  return chatbotSchema.parse(await response.json());
}

async function patchChatbot(id: string, payload: Partial<ChatbotConfig>) {
  const response = await fetch(`/api/cp/chatbots/${id}`, {
    method: "PATCH",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  if (!response.ok) throw new Error(`Save failed (${response.status})`);
  return chatbotSchema.parse(await response.json());
}

export default function ChatbotEditPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const t = useTranslations("chatbots");
  const queryClient = useQueryClient();

  const { data, isPending, isError } = useQuery({
    queryKey: ["chatbot", id],
    queryFn: () => fetchChatbot(id),
  });

  const save = useMutation({
    mutationFn: (payload: Partial<ChatbotConfig>) => patchChatbot(id, payload),
    onSuccess: (updated) => {
      queryClient.setQueryData(["chatbot", id], updated);
      void queryClient.invalidateQueries({ queryKey: ["chatbots"] });
    },
  });

  if (isPending) {
    return (
      <p className="text-sm text-zinc-500" role="status">
        {t("loading")}
      </p>
    );
  }

  if (isError || data === undefined) {
    return (
      <p className="text-sm text-red-600" role="alert">
        {t("error")}
      </p>
    );
  }

  return (
    <div className="max-w-2xl space-y-6">
      <div className="flex items-center justify-between gap-4">
        <h1 className="text-2xl font-bold tracking-tight">{t("editTitle")}</h1>
        <button
          type="button"
          onClick={() =>
            save.mutate({ status: data.status === "active" ? "paused" : "active" })
          }
          disabled={save.isPending}
          className={`rounded-md px-4 py-2 text-sm font-semibold focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-60 ${
            data.status === "active"
              ? "border border-zinc-300 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
              : "bg-emerald-600 text-white hover:bg-emerald-500"
          }`}
        >
          {data.status === "active" ? t("pause") : t("activate")}
        </button>
      </div>

      <p className="text-xs text-zinc-500">{t("activeNote")}</p>

      <ChatbotForm
        // Re-initialize whenever the server copy changes (React-idiomatic
        // alternative to setState-in-effect).
        key={`${data.chatbot_id}:${data.updated_at}`}
        chatbot={data}
        pending={save.isPending}
        success={save.isSuccess}
        failed={save.isError}
        onSave={(payload) => save.mutate(payload)}
        saveLabel={save.isPending ? t("saving") : t("save")}
      />
    </div>
  );
}

function ChatbotForm({
  chatbot,
  pending,
  success,
  failed,
  onSave,
  saveLabel,
}: {
  chatbot: ChatbotConfig;
  pending: boolean;
  success: boolean;
  failed: boolean;
  onSave: (payload: Partial<ChatbotConfig>) => void;
  saveLabel: string;
}) {
  const t = useTranslations("chatbots");
  const [name, setName] = useState(chatbot.name);
  const [prompt, setPrompt] = useState(chatbot.system_prompt ?? "");
  const [provider, setProvider] = useState<ChatbotConfig["provider"]>(chatbot.provider);
  const [model, setModel] = useState(chatbot.model ?? "");

  const inputClass =
    "w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900";

  return (
      <form
        onSubmit={(event) => {
          event.preventDefault();
          onSave({
            name: name.trim(),
            system_prompt: prompt.trim() === "" ? null : prompt,
            provider,
            model: model.trim() === "" ? null : model.trim(),
          });
        }}
        className="space-y-4"
      >
        <div className="space-y-1">
          <label htmlFor="name" className="block text-sm font-medium">
            {t("nameLabel")}
          </label>
          <input
            id="name"
            value={name}
            maxLength={100}
            required
            onChange={(event) => setName(event.target.value)}
            className={inputClass}
          />
        </div>

        <div className="space-y-1">
          <label htmlFor="prompt" className="block text-sm font-medium">
            {t("promptLabel")}
          </label>
          <textarea
            id="prompt"
            rows={6}
            maxLength={8000}
            value={prompt}
            placeholder={t("promptPlaceholder")}
            onChange={(event) => setPrompt(event.target.value)}
            className={inputClass}
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1">
            <label htmlFor="provider" className="block text-sm font-medium">
              {t("providerLabel")}
            </label>
            <select
              id="provider"
              value={provider}
              onChange={(event) =>
                setProvider(event.target.value as ChatbotConfig["provider"])
              }
              className={inputClass}
            >
              <option value="fake">fake (demo)</option>
              <option value="anthropic">anthropic</option>
              <option value="openai">openai</option>
            </select>
          </div>
          <div className="space-y-1">
            <label htmlFor="model" className="block text-sm font-medium">
              {t("modelLabel")}
            </label>
            <input
              id="model"
              value={model}
              maxLength={100}
              placeholder={t("modelPlaceholder")}
              onChange={(event) => setModel(event.target.value)}
              className={inputClass}
            />
          </div>
        </div>

        <div className="flex items-center gap-3">
          <button
            type="submit"
            disabled={pending}
            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-60"
          >
            {saveLabel}
          </button>
          {success && (
            <span className="text-sm text-emerald-600" role="status">
              {t("saved")}
            </span>
          )}
          {failed && (
            <span className="text-sm text-red-600" role="alert">
              {t("saveError")}
            </span>
          )}
        </div>
      </form>
  );
}
