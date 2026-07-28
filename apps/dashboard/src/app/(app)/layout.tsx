import Link from "next/link";
import { getTranslations } from "next-intl/server";

import { AvailabilityToggle } from "@/components/availability-toggle";
import { BrandLogo } from "@/components/brand-logo";
import {
  IconArrowRight,
  IconBell,
  IconCanned,
  IconChannels,
  IconChatbots,
  IconContacts,
  IconConversations,
  IconDashboard,
  IconDepartments,
  IconDevelopers,
  IconKnowledge,
  IconProfile,
  IconSearch,
  IconSparkles,
  IconVisitors,
  IconWidget,
} from "@/components/icons";
import { SidebarNav } from "@/components/sidebar-nav";
import { ThemeToggle } from "@/components/theme-toggle";
import { ApiError, apiJson } from "@/lib/api/server";
import { userSchema } from "@/lib/api/schemas";

import { logout } from "../login/actions";

/**
 * Authenticated shell (§3): grouped sidebar + header. Keyboard-first: skip
 * link, landmark roles, focus-visible everywhere.
 */
export default async function AppLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  const t = await getTranslations("shell");

  let userName = "";
  try {
    userName = (await apiJson("/api/user", (d) => userSchema.parse(d))).name;
  } catch (error) {
    // A 401 is handled by page-level fetches / middleware; the shell just
    // renders a neutral avatar rather than crashing the whole layout.
    if (!(error instanceof ApiError)) throw error;
  }
  const initial = userName.trim().charAt(0).toUpperCase() || "?";

  const mainNav = [
    { href: "/", label: t("dashboard"), icon: <IconDashboard /> },
    { href: "/conversations", label: t("conversations"), icon: <IconConversations /> },
    { href: "/visitors", label: t("visitors"), icon: <IconVisitors /> },
    { href: "/contacts", label: t("contacts"), icon: <IconContacts /> },
    { href: "/chatbots", label: t("chatbots"), icon: <IconChatbots /> },
    { href: "/knowledge", label: t("knowledge"), icon: <IconKnowledge /> },
    { href: "/settings/departments", label: t("departments"), icon: <IconDepartments /> },
    { href: "/settings/channels", label: t("channels"), icon: <IconChannels /> },
    { href: "/settings/widget", label: t("widgetSetup"), icon: <IconWidget /> },
    { href: "/settings/canned", label: t("cannedReplies"), icon: <IconCanned /> },
  ];
  const settingsNav = [
    { href: "/settings/developers", label: t("developers"), icon: <IconDevelopers /> },
    { href: "/settings/profile", label: t("profile"), icon: <IconProfile /> },
  ];

  return (
    <div className="flex min-h-dvh">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-indigo-600 focus:px-3 focus:py-2 focus:text-white"
      >
        Skip to content
      </a>

      <aside className="hidden w-64 shrink-0 flex-col border-e border-zinc-200 bg-white p-3 md:flex dark:border-zinc-800 dark:bg-zinc-900">
        <div className="mb-6 px-2 py-1">
          <BrandLogo className="h-9 w-auto" />
        </div>

        <div className="flex-1 space-y-5 overflow-y-auto">
          <SidebarNav items={mainNav} />
          <div>
            <p className="px-3 pb-1 text-[11px] font-semibold tracking-wider text-zinc-400 uppercase">
              {t("settings")}
            </p>
            <SidebarNav items={settingsNav} />
          </div>
        </div>

        {/* Upsell card — mirrors the widget's AI framing. */}
        <Link
          href="/chatbots"
          className="mt-4 block rounded-2xl bg-gradient-to-br from-indigo-500 to-fuchsia-500 p-4 text-white shadow-sm transition-transform hover:scale-[1.01] focus-visible:ring-2 focus-visible:ring-indigo-400 focus-visible:outline-none"
        >
          <span className="grid size-8 place-items-center rounded-lg bg-white/20" aria-hidden>
            <IconSparkles />
          </span>
          <p className="mt-2 text-sm font-semibold leading-snug">{t("upsellTitle")}</p>
          <span className="mt-2 inline-flex items-center gap-1 rounded-lg bg-white/20 px-2.5 py-1 text-xs font-medium">
            {t("upsellCta")}
            <IconArrowRight />
          </span>
        </Link>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col bg-zinc-50 dark:bg-zinc-950">
        <header className="sticky top-0 z-10 flex items-center gap-3 border-b border-zinc-200 bg-white/80 px-4 py-2.5 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/80">
          <form action="/conversations" className="relative hidden max-w-xs flex-1 sm:block">
            <span className="pointer-events-none absolute inset-y-0 start-2.5 grid place-items-center text-zinc-400" aria-hidden>
              <IconSearch />
            </span>
            <input
              type="search"
              name="search"
              placeholder={t("searchPlaceholder")}
              aria-label={t("searchPlaceholder")}
              className="w-full rounded-lg border border-zinc-200 bg-zinc-50 py-1.5 ps-9 pe-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
            />
          </form>

          <div className="ms-auto flex items-center gap-2">
            <Link
              href="/conversations"
              aria-label={t("notifications")}
              className="grid size-9 place-items-center rounded-lg border border-zinc-200 text-zinc-500 transition-colors hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:outline-none dark:border-zinc-700 dark:hover:bg-zinc-800"
            >
              <IconBell />
            </Link>
            <AvailabilityToggle />
            <ThemeToggle />
            <span
              className="grid size-9 place-items-center rounded-full bg-indigo-600 text-sm font-semibold text-white"
              aria-hidden
            >
              {initial}
            </span>
            <form action={logout}>
              <button
                type="submit"
                className="rounded-md border border-zinc-300 px-3 py-1 text-sm font-medium transition-colors hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:hover:bg-zinc-800"
              >
                {t("signOut")}
              </button>
            </form>
          </div>
        </header>

        <main id="main" className="flex-1 p-6">
          {children}
        </main>
      </div>
    </div>
  );
}
