import { getTranslations } from "next-intl/server";

import { Insights } from "./insights";

export default async function DashboardPage() {
  const t = await getTranslations("dashboard");

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold tracking-tight">{t("title")}</h1>
      <Insights />
    </div>
  );
}
