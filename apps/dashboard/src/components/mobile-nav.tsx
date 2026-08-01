"use client";

import { useState } from "react";

import { BrandLogo } from "./brand-logo";
import { SidebarNav } from "./sidebar-nav";

import type { ReactNode } from "react";

type NavItem = { href: string; label: string; icon: ReactNode };

/**
 * Small-screen navigation: hamburger in the header opening a slide-over
 * drawer with the same nav the md+ sidebar shows. Closes on backdrop tap,
 * the ✕ button, or following any link.
 */
export function MobileNav({
  mainNav,
  settingsNav,
  settingsLabel,
}: {
  mainNav: NavItem[];
  settingsNav: NavItem[];
  settingsLabel: string;
}) {
  const [open, setOpen] = useState(false);

  return (
    <div className="md:hidden">
      <button
        type="button"
        aria-label="Open navigation"
        aria-expanded={open}
        onClick={() => setOpen(true)}
        className="grid size-9 place-items-center rounded-lg border border-zinc-200 text-zinc-500 transition-colors hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:outline-none dark:border-zinc-700 dark:hover:bg-zinc-800"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden>
          <path d="M4 6h16M4 12h16M4 18h16" />
        </svg>
      </button>

      {open && (
        <div className="fixed inset-0 z-40" role="dialog" aria-modal="true" aria-label="Navigation">
          <button
            type="button"
            aria-label="Close navigation"
            onClick={() => setOpen(false)}
            className="absolute inset-0 w-full bg-zinc-900/50"
          />
          <div
            className="absolute inset-y-0 start-0 flex w-72 max-w-[85vw] flex-col overflow-y-auto bg-white p-3 shadow-xl dark:bg-zinc-900"
            onClickCapture={(e) => {
              if (e.target instanceof Element && e.target.closest("a") !== null) {
                setOpen(false);
              }
            }}
          >
            <div className="mb-4 flex items-center justify-between px-2 py-1.5">
              <BrandLogo className="h-9 w-auto" />
              <button
                type="button"
                aria-label="Close navigation"
                onClick={() => setOpen(false)}
                className="grid size-8 place-items-center rounded-lg text-zinc-500 hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:outline-none dark:hover:bg-zinc-800"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden>
                  <path d="M6 6l12 12M18 6L6 18" />
                </svg>
              </button>
            </div>

            <div className="space-y-5">
              <SidebarNav items={mainNav} />
              <div>
                <p className="px-3 pb-1 text-[11px] font-semibold tracking-wider text-zinc-400 uppercase">
                  {settingsLabel}
                </p>
                <SidebarNav items={settingsNav} />
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
