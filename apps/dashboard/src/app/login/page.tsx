import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";

import { BrandMark } from "@/components/brand-logo";
import { getSessionToken } from "@/lib/auth/session";

import { LoginForm } from "./login-form";

export const metadata: Metadata = { title: "Sign in — mkEngage" };

export default async function LoginPage() {
  if ((await getSessionToken()) !== null) {
    redirect("/");
  }

  const t = await getTranslations("login");

  return (
    <main className="relative flex min-h-dvh items-center justify-center overflow-hidden bg-zinc-50 px-4 dark:bg-zinc-950">
      {/* Soft brand glow behind the card. */}
      <div
        aria-hidden
        className="pointer-events-none absolute -top-32 left-1/2 size-[36rem] -translate-x-1/2 rounded-full bg-indigo-500/10 blur-3xl dark:bg-indigo-500/15"
      />
      <div className="relative w-full max-w-sm space-y-6">
        <div className="flex flex-col items-center space-y-2 text-center">
          <BrandMark className="h-14 w-auto" />
          <h1 className="text-2xl font-bold tracking-tight">
            <span className="bg-gradient-to-br from-[#ff1e6f] to-[#8b3dff] bg-clip-text text-transparent">mk</span>
            <span className="text-zinc-900 dark:text-zinc-50">Engage</span>
          </h1>
          <p className="text-sm text-zinc-600 dark:text-zinc-400">{t("subtitle")}</p>
        </div>
        <div className="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
          <LoginForm />
        </div>
      </div>
    </main>
  );
}
