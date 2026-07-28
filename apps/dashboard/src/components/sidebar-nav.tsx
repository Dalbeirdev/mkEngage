"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

import type { ReactNode } from "react";

/** Sidebar navigation with icons + active-route highlighting. */
export function SidebarNav({
  items,
}: {
  items: { href: string; label: string; icon: ReactNode }[];
}) {
  const pathname = usePathname();

  return (
    <nav aria-label="Primary">
      <ul className="space-y-0.5">
        {items.map((item) => {
          const active =
            item.href === "/" ? pathname === "/" : pathname.startsWith(item.href);
          return (
            <li key={item.href}>
              <Link
                href={item.href}
                aria-current={active ? "page" : undefined}
                className={`flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:outline-none ${
                  active
                    ? "bg-indigo-600 text-white shadow-sm shadow-indigo-600/20"
                    : "text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                }`}
              >
                <span
                  className={`shrink-0 ${active ? "text-white" : "text-zinc-400"}`}
                  aria-hidden
                >
                  {item.icon}
                </span>
                {item.label}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
