"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { contactListSchema } from "@/lib/api/schemas";

async function fetchContacts() {
  const response = await fetch("/api/cp/contacts", { cache: "no-store" });
  if (!response.ok) throw new Error(`Failed to load contacts (${response.status})`);
  return contactListSchema.parse(await response.json()).data;
}

/** Contact list — identified visitors become person records here (A4). */
export default function ContactsPage() {
  const t = useTranslations("contacts");

  const { data, isPending, isError } = useQuery({
    queryKey: ["contacts"],
    queryFn: fetchContacts,
    refetchInterval: 15000,
    refetchIntervalInBackground: true,
  });

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold tracking-tight">{t("title")}</h1>

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

      {data !== undefined && data.length === 0 && (
        <div className="rounded-xl border border-dashed border-zinc-300 p-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
          {t("empty")}
        </div>
      )}

      {data !== undefined && data.length > 0 && (
        <div className="overflow-x-auto rounded-2xl border border-zinc-200 bg-white shadow-sm dark:bg-zinc-900 dark:border-zinc-800">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-zinc-200 text-start dark:border-zinc-800">
                <th scope="col" className="px-4 py-2 text-start font-medium">{t("colName")}</th>
                <th scope="col" className="px-4 py-2 text-start font-medium">{t("colEmail")}</th>
                <th scope="col" className="px-4 py-2 text-start font-medium">{t("colExternalId")}</th>
                <th scope="col" className="px-4 py-2 text-start font-medium">{t("colCreated")}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-100 dark:divide-zinc-900">
              {data.map((contact) => (
                <tr key={contact.contact_id}>
                  <td className="px-4 py-2 font-medium">{contact.name ?? t("unnamed")}</td>
                  <td className="px-4 py-2">{contact.email ?? "—"}</td>
                  <td className="px-4 py-2 font-mono text-xs">{contact.external_id ?? "—"}</td>
                  <td className="px-4 py-2 text-zinc-500">
                    {contact.created_at === null
                      ? "—"
                      : new Date(contact.created_at).toLocaleDateString()}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
