import Link from "next/link";
import { getTranslations } from "next-intl/server";

import { ThemeToggle } from "@/components/theme-toggle";

import { logout } from "../login/actions";

/**
 * Authenticated shell (§3): sidebar navigation + header. Keyboard-first:
 * skip link, landmark roles, focus-visible everywhere.
 */
export default async function AppLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  const t = await getTranslations("shell");

  const navigation = [
    { href: "/", label: t("dashboard") },
    { href: "/conversations", label: t("conversations") },
    { href: "/contacts", label: t("contacts") },
    { href: "/chatbots", label: t("chatbots") },
    { href: "/settings/departments", label: t("departments") },
    { href: "/settings/widget", label: t("widgetSetup") },
    { href: "/settings/profile", label: t("profile") },
  ];

  return (
    <div className="flex min-h-dvh">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-md focus:bg-indigo-600 focus:px-3 focus:py-2 focus:text-white"
      >
        Skip to content
      </a>

      <aside className="hidden w-56 shrink-0 border-e border-zinc-200 bg-zinc-50 p-4 md:block dark:border-zinc-800 dark:bg-zinc-900">
        <div className="mb-6 text-lg font-bold tracking-tight">mkEngage</div>
        <nav aria-label="Primary">
          <ul className="space-y-1">
            {navigation.map((item) => (
              <li key={item.href}>
                <Link
                  href={item.href}
                  className="block rounded-md px-3 py-2 text-sm hover:bg-zinc-200 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:hover:bg-zinc-800"
                >
                  {item.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center justify-end gap-2 border-b border-zinc-200 px-4 py-2 dark:border-zinc-800">
          <ThemeToggle />
          <form action={logout}>
            <button
              type="submit"
              className="rounded-md border border-zinc-300 px-3 py-1 text-sm hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:hover:bg-zinc-800"
            >
              {t("signOut")}
            </button>
          </form>
        </header>

        <main id="main" className="flex-1 p-6">
          {children}
        </main>
      </div>
    </div>
  );
}
