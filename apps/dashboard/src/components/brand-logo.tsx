"use client";

import { useEffect, useId, useRef, useState } from "react";

/**
 * mkEngage brand marks.
 *
 * `BrandLogo` renders the real logo art from `/public/brand/mkengage-logo.png`
 * (the mascot + wordmark lockup). If that file is missing it falls back to the
 * built-in vector lockup, so the app never shows a broken image — drop the PNG
 * in and it lights up automatically.
 *
 * `BrandMark` is the icon-only robot-in-a-speech-bubble vector, used for the
 * fallback and any spot that needs a compact, theme-aware, recolorable mark.
 */

export function BrandMark({ className }: { className?: string }) {
  // useId keeps the gradient id stable across SSR/hydration; strip ':' so it's
  // a valid url(#…) reference.
  const gradId = `mk${useId().replace(/:/g, "")}`;

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

function VectorLockup({ className }: { className?: string }) {
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

export function BrandLogo({ className }: { className?: string }) {
  const [failed, setFailed] = useState(false);
  const ref = useRef<HTMLImageElement>(null);

  // The image can 404 BEFORE React hydrates and attaches onError (SSR gotcha):
  // a broken, already-complete image fires no late error event. Catch that on
  // mount so the vector fallback still kicks in.
  useEffect(() => {
    const img = ref.current;
    if (img !== null && img.complete && img.naturalWidth === 0) {
      setFailed(true);
    }
  }, []);

  if (failed) {
    return <VectorLockup />;
  }

  return (
    // The logo art is a fixed brand asset, not user content — next/image adds
    // no value here and can't statically import a maybe-absent file.
    // eslint-disable-next-line @next/next/no-img-element
    <img
      ref={ref}
      src="/brand/mkengage-logo.png"
      alt="mkEngage"
      className={className ?? "h-8 w-auto"}
      onError={() => setFailed(true)}
    />
  );
}
