import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";

import { ApiError, apiJson } from "@/lib/api/server";
import { userSchema, type User } from "@/lib/api/schemas";
import { clearSessionToken } from "@/lib/auth/session";

/**
 * Server component fetching the authenticated user from the control plane
 * (§3 server-side authorization). A 401 means the token was revoked or
 * expired — clear the cookie and bounce to login.
 */
export default async function ProfilePage() {
  const t = await getTranslations("profile");

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

  const rows: Array<[string, string]> = [
    [t("name"), user.name],
    [t("email"), user.email],
    [t("organizationId"), user.organization_id],
    [t("status"), user.status],
  ];

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold tracking-tight">{t("title")}</h1>
      <dl className="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
        {rows.map(([label, value]) => (
          <div key={label} className="grid grid-cols-3 gap-4 px-4 py-3 text-sm">
            <dt className="font-medium text-zinc-600 dark:text-zinc-400">{label}</dt>
            <dd className="col-span-2 break-all">{value}</dd>
          </div>
        ))}
      </dl>
    </div>
  );
}
