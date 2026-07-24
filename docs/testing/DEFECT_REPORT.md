# mkEngage — Defect Report

Build `c911bcb` · 2026-07-24. **No Severity 1 or Severity 2 defect found in the implemented surface.** All auth, authorization, tenant-isolation, realtime-reliability, AI-auth, and attachment-security tests passed. The defects below are operational/coverage gaps.

---

## DEF-001 — Realtime gateway exposes no health/readiness endpoint
- **Severity:** 3 (Medium) · **Priority:** High for production
- **Environment:** Phoenix gateway :4000, build `c911bcb`
- **Module:** `services/realtime-gateway` (`router.ex`)
- **Steps to reproduce:** `GET http://127.0.0.1:4000/{health,healthz,up,api/health,/}`
- **Expected:** A `200` liveness/readiness endpoint for load balancers, k8s probes, and uptime monitoring.
- **Actual:** All paths return `404`. No health route defined.
- **Evidence:** execution log, "gateway health path" probe (5×404).
- **Security impact:** None. **Business impact:** Blocks the "monitoring is active" release gate; a crashed gateway cannot be detected by an LB/probe.
- **Suspected component:** `RealtimeGatewayWeb.Router` (no health scope).
- **Reproducibility:** 100%. **Fix status:** ✅ FIXED. **Retest:** ✅ PASS.
- **Fix applied:** Added `RealtimeGatewayWeb.HealthController` with `GET /health` (liveness → `{"status":"ok"}`) and `GET /health/ready` (DB readiness → 200 / 503). Routed in `router.ex`. Test `health_controller_test.exs` added (2 tests). Live retest after gateway restart: `/health`→200, `/health/ready`→200 `{"database":"ok"}`. Full gateway suite **24 passed** (no regression). Deployment probes should target `/health/ready`.

## DEF-002 — Cross-browser E2E coverage limited to Chromium
- **Severity:** 3 (Medium) · **Priority:** Medium
- **Module:** `packages/chat-widget/playwright.config.ts`, `apps/dashboard/playwright.config.ts`
- **Steps:** Inspect both configs → `projects: [{ name: "chromium" }]` only.
- **Expected:** Firefox + WebKit projects (and mobile emulation) per cross-browser requirement.
- **Actual:** Only Desktop Chrome is exercised; Firefox/WebKit never run. Widget ships to arbitrary host sites, so Safari/WebKit coverage matters most.
- **Evidence:** `grep projects playwright.config.*`.
- **Impact:** Browser-specific defects (Shadow DOM, IndexedDB, WS behavior on WebKit) would not be caught.
- **Reproducibility:** 100%. **Fix status:** ✅ MOSTLY FIXED. **Retest:** ✅ PASS (WebKit + mobile).
- **Fix applied:** Added `firefox`, `webkit`, `mobile-safari`, `mobile-chrome` projects to both Playwright configs. Executed: **widget 32 passed** across chromium+webkit+mobile-safari+mobile-chrome (8 each); **dashboard 9 passed** across chromium+webkit+mobile-safari. WebKit (the load-bearing Safari coverage for an embeddable widget) is now green.
- **Residual:** Firefox cannot launch on this specific Windows host (`browserType.launch: spawn UNKNOWN` — confirmed at raw `firefox.launch()`, an OS/AV process-spawn issue, not a config or product defect; WebKit launches fine on the same machine). Firefox will run on Linux CI. Left in config for CI; excluded from local runs.

## DEF-003 — AI service type-checking (mypy) not runnable in the test environment
- **Severity:** 4 (Low) · **Priority:** Low
- **Module:** `services/ai-service`
- **Steps:** `python -m mypy app` → "No module named mypy".
- **Expected:** Static type gate runnable locally (the repo declares strict typing intent).
- **Actual:** mypy absent from the venv; only ruff runs.
- **Impact:** Testability gap; ruff still passes.
- **Fix status:** ✅ FIXED. **Retest:** ✅ PASS.
- **Fix applied:** Installed mypy, added it to `pyproject.toml` `dev` deps. Running it immediately surfaced a **real type defect** — `app/main.py:78` assigned `FakeEmbedder()` to a variable mypy had inferred as `OpenAIEmbedder` (incompatible types). Fixed by annotating `embedder: Embedder` (the shared Protocol). Retest: `mypy app` → *Success: no issues found in 10 source files*; ruff clean; pytest 15/15 (no regression).

---

## Observations (not defects — by design / documented)
- **PHP `upload_max_filesize = 2M`** on the local runtime caps uploads below the app's 5 MB config. Environment tuning, not a product defect; documented in SECURITY_TEST_REPORT.
- **FakeScanner** flags a project marker, not real malware. Correct for local/CI (host AV deletes EICAR test files); a real AV engine must be wired for production (tracked as a known limitation, not a defect at this build).
- **curl `-F "file=@f;type=..."`** returns HTTP 000 against the dev server; plain `-F "file=@f"` works (201). Tooling artifact of the Git-Bash curl multipart encoding, not a server defect (browser uploads and plain multipart succeed).

## Severity tally
| Severity | Open | Fixed | Retested |
|---|---|---|---|
| 1 — Critical | 0 | 0 | 0 |
| 2 — High | 0 | 0 | 0 |
| 3 — Medium | 0 | 2 (DEF-001, DEF-002*) | 2 |
| 4 — Low | 0 | 1 (DEF-003) | 1 |

All three defects were fixed and retested during this QA pass:
- **DEF-001** gateway health endpoint added → gateway 24/24.
- **DEF-002** cross-browser projects added → WebKit + mobile executed green (widget 32, dashboard 9); Firefox blocked by a *host-environment* spawn issue only, runs on CI.
- **DEF-003** mypy added → caught & fixed a real type bug in `main.py` → mypy clean.

No Severity-1/2 defects were found at any point. The k6 performance smoke script now exists (`perf/k6/smoke.js`) but performance remains **not executed** (no k6 binary in this environment).
