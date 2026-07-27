import { getTranslations } from "next-intl/server";

import { AvailabilityToggle } from "@/components/availability-toggle";
import {
  IconChatbots,
  IconContacts,
  IconConversations,
  IconDashboard,
  IconDepartments,
  IconKnowledge,
  IconProfile,
  IconVisitors,
  IconWidget,
} from "@/components/icons";
import { SidebarNav } from "@/components/sidebar-nav";
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
    { href: "/", label: t("dashboard"), icon: <IconDashboard /> },
    { href: "/conversations", label: t("conversations"), icon: <IconConversations /> },
    { href: "/visitors", label: t("visitors"), icon: <IconVisitors /> },
    { href: "/contacts", label: t("contacts"), icon: <IconContacts /> },
    { href: "/chatbots", label: t("chatbots"), icon: <IconChatbots /> },
    { href: "/knowledge", label: t("knowledge"), icon: <IconKnowledge /> },
    { href: "/settings/departments", label: t("departments"), icon: <IconDepartments /> },
    { href: "/settings/widget", label: t("widgetSetup"), icon: <IconWidget /> },
    { href: "/settings/canned", label: t("cannedReplies"), icon: <IconConversations /> },
    { href: "/settings/channels", label: t("channels"), icon: <IconVisitors /> },
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

      <aside className="hidden w-60 shrink-0 border-e border-zinc-200 bg-white p-3 md:flex md:flex-col dark:border-zinc-800 dark:bg-zinc-900">
        <div className="mb-6 flex items-center gap-2 px-2 py-1">
          <span className="grid size-8 place-items-center rounded-lg bg-indigo-600 text-sm font-bold text-white">
            m
          </span>
          <span className="text-lg font-bold tracking-tight">mkEngage</span>
        </div>
        <SidebarNav items={navigation} />
      </aside>

      <div className="flex min-w-0 flex-1 flex-col bg-zinc-50 dark:bg-zinc-950">
        <header className="sticky top-0 z-10 flex items-center justify-end gap-2 border-b border-zinc-200 bg-white/80 px-4 py-2.5 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/80">
          <AvailabilityToggle />
          <ThemeToggle />
          <form action={logout}>
            <button
              type="submit"
              className="rounded-md border border-zinc-300 px-3 py-1 text-sm font-medium transition-colors hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:hover:bg-zinc-800"
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
