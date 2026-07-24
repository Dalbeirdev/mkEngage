# mkEngage — Accessibility Report

Build `c911bcb` · 2026-07-24 · Target: WCAG 2.2 AA

## Automated (executed)
| Surface | Tool | Result |
|---|---|---|
| Chat widget — open panel | axe-core via Playwright | **PASS** — no serious violations |
| Dashboard — login page (light + dark) | axe-core via Playwright | **PASS** — no serious violations, both themes |

Additional a11y correctness verified in the E2E suites during development:
- Message log containers use `div` (not `ul`/`role="log"` on lists) after an earlier axe `listitem` violation was fixed — confirmed still green.
- Widget launcher/panel keyboard-operable, `aria-expanded`, Escape-to-close (Playwright spec).
- Typing indicator is `aria-hidden` so the polite live region does not announce it; reduced-motion path renders static dots.
- Login form: labels, `autocomplete`, `aria-invalid` wiring (component review).

## Manual (NOT executed — honest gap)
The following require manual assistive-technology passes not performed in this run and are **not claimed as passing**:
- Screen-reader announcement of live chat message arrival (NVDA/VoiceOver).
- Full keyboard focus-order and focus-trap audit across every dashboard screen.
- 200% zoom reflow across all screens.
- Colour-contrast audit of every component state (automated axe covers rendered pages tested only).
- Touch-target sizing on real mobile devices.

Scope note: several assignment screens (Flow Builder, Insights, Billing, Automations, Super-admin) are **not implemented**, so their accessibility is Not Available.

## Verdict
**No critical accessibility blocker found on the tested surfaces (widget panel, login).** Automated coverage is green; a full manual WCAG 2.2 AA audit across all implemented dashboard screens remains outstanding before an accessibility sign-off.
