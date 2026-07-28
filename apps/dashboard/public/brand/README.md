# Brand assets

The dashboard's `BrandLogo` component (`src/components/brand-logo.tsx`) loads
the logo art from this folder at runtime.

## Required file

| File | Used by | Notes |
| --- | --- | --- |
| `mkengage-logo.png` | login header, sidebar | The full lockup (mascot + wordmark). |

Drop `mkengage-logo.png` here and it appears automatically — no code change.
Until it exists, `BrandLogo` falls back to the built-in vector lockup, so the
app never shows a broken image.

## Recommended export settings

- **Transparent background** (not white) — the sidebar background is dark in
  dark mode; a white-boxed PNG looks wrong there.
- Trim the surrounding whitespace so the art fills the frame (it renders only
  ~36px tall in the sidebar; tight cropping keeps it legible).
- ~2× the display size for retina: roughly **900–1200px wide** is plenty.
- PNG-24 with alpha, or an SVG if you have a vectorized version.

## Optional: dark-mode variant

The wordmark ink is dark navy, which is low-contrast on the dark sidebar. If
you want it crisp in both themes, also add `mkengage-logo-dark.png` (same art,
light wordmark) and ask to wire theme-switching — not enabled yet.
