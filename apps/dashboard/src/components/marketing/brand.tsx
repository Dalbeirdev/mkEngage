/** Shared brand assets for the marketing site. The gradient is defined once
 *  (BrandGradientDef, rendered by the marketing layout); every mark references
 *  it via url(#mkg). */

export function BrandGradientDef() {
  return (
    <svg width="0" height="0" style={{ position: "absolute" }} aria-hidden="true">
      <defs>
        <linearGradient id="mkg" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#3b7bf6" />
          <stop offset="0.5" stopColor="#8b3dff" />
          <stop offset="1" stopColor="#ec3f94" />
        </linearGradient>
      </defs>
    </svg>
  );
}

export function BrandMark({ size = 30, faceLight = false }: { size?: number; faceLight?: boolean }) {
  return (
    <svg width={size} height={size} viewBox="-6 -16 86 82" aria-hidden="true">
      <path
        d="M14 4h44a14 14 0 0 1 14 14v28a14 14 0 0 1-14 14H37l-11 11v-11H14A14 14 0 0 1 0 46V18A14 14 0 0 1 14 4z"
        fill="none"
        stroke="url(#mkg)"
        strokeWidth="5"
        strokeLinejoin="round"
      />
      <line x1="36" y1="-4" x2="36" y2="6" stroke="url(#mkg)" strokeWidth="4" strokeLinecap="round" />
      <circle cx="36" cy="-8" r="4.5" fill="url(#mkg)" />
      <rect x="18" y="14" width="36" height="28" rx="12" fill={faceLight ? "#ffffff" : "#141a2e"} />
      {!faceLight && (
        <>
          <circle cx="28" cy="26" r="5" fill="#fff" />
          <circle cx="44" cy="26" r="5" fill="#fff" />
        </>
      )}
      <circle cx="29" cy="27" r="2.4" fill="#141a2e" />
      <circle cx="45" cy="27" r="2.4" fill="#141a2e" />
      {!faceLight && <path d="M31 34q5 6 10 0" fill="none" stroke="#ec3f94" strokeWidth="3" strokeLinecap="round" />}
    </svg>
  );
}
