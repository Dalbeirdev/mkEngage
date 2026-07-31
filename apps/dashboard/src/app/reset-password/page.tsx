import type { Metadata } from "next";
import Link from "next/link";

import { BrandLogo } from "@/components/brand-logo";

import { ResetForm } from "./reset-form";

export const metadata: Metadata = { title: "Choose a new password — mkEngage" };

export default async function ResetPasswordPage({
  searchParams,
}: {
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>;
}) {
  const params = await searchParams;
  const organization = typeof params.organization === "string" ? params.organization : "";
  const email = typeof params.email === "string" ? params.email : "";
  const token = typeof params.token === "string" ? params.token : "";
  const linkComplete = organization !== "" && email !== "" && token !== "";

  return (
    <main className="relative flex min-h-dvh items-center justify-center overflow-hidden bg-zinc-50 px-4 dark:bg-zinc-950">
      <div
        aria-hidden
        className="pointer-events-none absolute -top-32 left-1/2 size-[36rem] -translate-x-1/2 rounded-full bg-indigo-500/10 blur-3xl dark:bg-indigo-500/15"
      />
      <div className="relative w-full max-w-sm space-y-6">
        <div className="flex flex-col items-center space-y-3 text-center">
          <BrandLogo className="h-16 w-auto" />
          <h1 className="sr-only">mkEngage</h1>
          <p className="text-sm text-zinc-600 dark:text-zinc-400">Choose a new password</p>
        </div>
        <div className="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
          {linkComplete ? (
            <ResetForm organization={organization} email={email} token={token} />
          ) : (
            <p className="text-sm text-zinc-700 dark:text-zinc-300">
              This reset link is incomplete.{" "}
              <Link href="/forgot-password" className="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                Request a new one
              </Link>
              .
            </p>
          )}
        </div>
      </div>
    </main>
  );
}
