import { Greeting } from "@/components/greeting";
import { userSchema } from "@/lib/api/schemas";
import { ApiError, apiJson } from "@/lib/api/server";

import { DashboardView } from "./insights";

export default async function DashboardPage() {
  let name = "";
  try {
    name = (await apiJson("/api/user", (d) => userSchema.parse(d))).name;
  } catch (error) {
    if (!(error instanceof ApiError)) throw error;
  }
  const firstName = name.split(" ")[0] || "there";

  return (
    <div className="space-y-6">
      <Greeting name={firstName} />
      <DashboardView />
    </div>
  );
}
