/**
 * Shared UI class tokens so every screen uses the same card / button / input
 * / heading language. Import these instead of re-typing Tailwind strings.
 */

/** White card that lifts off the grey page background. */
export const card =
  "rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900";

/** Card with default padding. */
export const cardPad = `${card} p-5`;

/** Primary (indigo) action button. */
export const btnPrimary =
  "inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:outline-none disabled:opacity-60";

/** Secondary (outlined) button. */
export const btnSecondary =
  "inline-flex items-center justify-center gap-2 rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium transition-colors hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:outline-none disabled:opacity-60 dark:border-zinc-700 dark:hover:bg-zinc-800";

/** Small outlined button (table row actions). */
export const btnSmall =
  "inline-flex items-center gap-1.5 rounded-md border border-zinc-300 px-2.5 py-1 text-xs font-medium transition-colors hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:outline-none disabled:opacity-60 dark:border-zinc-700 dark:hover:bg-zinc-800";

/** Text/select/textarea input. */
export const input =
  "w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm outline-none transition-shadow focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900";

/** Page title (h1). */
export const pageTitle = "text-2xl font-bold tracking-tight";

/** Muted helper text. */
export const muted = "text-sm text-zinc-500 dark:text-zinc-400";

/** Dashed empty-state panel. */
export const emptyState =
  "rounded-2xl border border-dashed border-zinc-300 bg-white/50 p-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900/50 dark:text-zinc-400";
