/**
 * mkEngage brand marks. `BrandMark` is the icon-only robot-in-a-speech-bubble
 * (favicon, collapsed sidebar); `BrandLogo` pairs it with the wordmark — "mk"
 * in the pink→purple brand gradient, "Engage" in the foreground ink so it
 * flips correctly between light and dark. Vector, so it stays crisp at every
 * size.
 */

let gradientSeq = 0;

export function BrandMark({ className }: { className?: string }) {
  // Unique gradient id per instance so multiple marks on a page never clash.
  const gradId = `mk-brand-grad-${gradientSeq++}`;

  return (
    <svg
      viewBox="-6 -16 86 82"
      className={className}
      role="img"
      aria-label="mkEngage"
      xmlns="http://www.w3.org/2000/svg"
    >
      <defs>
        <linearGradient id={gradId} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#ff1e6f" />
          <stop offset="1" stopColor="#8b3dff" />
        </linearGradient>
      </defs>
      <path
        d="M14 4 h44 a14 14 0 0 1 14 14 v28 a14 14 0 0 1 -14 14 h-21 l-11 11 v-11 h-12 a14 14 0 0 1 -14 -14 v-28 a14 14 0 0 1 14 -14 z"
        fill="none"
        stroke={`url(#${gradId})`}
        strokeWidth="5"
        strokeLinejoin="round"
      />
      <line x1="36" y1="-4" x2="36" y2="6" stroke={`url(#${gradId})`} strokeWidth="4" strokeLinecap="round" />
      <circle cx="36" cy="-8" r="4.5" fill={`url(#${gradId})`} />
      <rect x="18" y="14" width="36" height="28" rx="10" fill="#141a2e" />
      <path d="M26 27 q3 4 6 0" fill="none" stroke="#ff1e6f" strokeWidth="3" strokeLinecap="round" />
      <path d="M40 27 q3 4 6 0" fill="none" stroke="#ff1e6f" strokeWidth="3" strokeLinecap="round" />
      <path d="M31 34 q5 5 10 0" fill="none" stroke="#ff1e6f" strokeWidth="3" strokeLinecap="round" />
    </svg>
  );
}

export function BrandLogo({ className }: { className?: string }) {
  return (
    <span className={`inline-flex items-center gap-2 ${className ?? ""}`}>
      <BrandMark className="h-7 w-auto" />
      <span className="text-lg font-bold tracking-tight">
        <span className="bg-gradient-to-br from-[#ff1e6f] to-[#8b3dff] bg-clip-text text-transparent">mk</span>
        <span className="text-zinc-900 dark:text-zinc-50">Engage</span>
      </span>
    </span>
  );
}
