# mkEngage — Test Matrix

Build `c911bcb` · 2026-07-24. Legend: **EXEC-PASS** executed & passed · **EXEC-FAIL** executed & failed · **BLOCKED** could not run · **REVIEW** static/code review only · **N/A** feature not implemented.

## A. Implemented surface — executed

| # | Area | Test | Result | Evidence |
|---|---|---|---|---|
| A1 | Auth | Unauthenticated → 401 on `/api/user`, `/api/conversations` | EXEC-PASS | live probe |
| A2 | Auth | Garbage bearer → 401 | EXEC-PASS | live probe |
| A3 | Auth | Wrong password → 422 (uniform, no user-enumeration) | EXEC-PASS | live probe |
| A4 | Auth | Token issuance rate limiting → 429 after 5 attempts | EXEC-PASS | live probe (`422×5 → 429×7`) |
| A5 | Auth | Gateway HMAC socket token: valid / expired / tampered / garbage | EXEC-PASS | ExUnit `token_test.exs` 5 tests |
| B1 | AuthZ | Visitor token → `/api/user` → 403 (ability-gated) | EXEC-PASS | live probe |
| B2 | AuthZ | Visitor token → agent `/api/conversations` → 403 | EXEC-PASS | live probe |
| C1 | Tenant isolation | RLS enabled+forced+policy on every tenant table | EXEC-PASS | Pest RlsIsolation |
| C2 | Tenant isolation | Fail-closed: zero rows / throws without context | EXEC-PASS | Pest (4 tests) |
| C3 | Tenant isolation | Org A context hides Org B rows; cross-tenant UPDATE/DELETE 0 rows | EXEC-PASS | Pest (2 tests) |
| C4 | Tenant isolation | INSERT with contradicting org_id rejected | EXEC-PASS | Pest |
| C5 | Tenant isolation | Non-superuser DB role (superuser would bypass RLS) | EXEC-PASS | Pest |
| C6 | Tenant isolation (HTTP) | Beta agent reads Alpha conversation by ID → 404 (no oracle) | EXEC-PASS | live probe |
| C7 | Tenant isolation (HTTP) | Beta agent reads Alpha messages → 404; Alpha reads own → 200 | EXEC-PASS | live probe |
| C8 | Tenant isolation (WS) | Cross-org channel join with valid other-org token → rejected | EXEC-PASS | ExUnit channel test |
| D1 | Realtime | Duplicate idempotency key → original ack, no re-broadcast | EXEC-PASS | ExUnit |
| D2 | Realtime | Sequence-ordered replay after last-seen | EXEC-PASS | ExUnit |
| D3 | Realtime | Join another visitor's conversation → rejected (no oracle) | EXEC-PASS | ExUnit |
| D4 | Realtime | Oversized / empty body rejected | EXEC-PASS | ExUnit |
| D5 | Realtime | Outbox→JetStream→consumer→broadcast delivery | EXEC-PASS | ExUnit consumer_test + Phase 16 live |
| D6 | Realtime | Persist-before-confirm; message survives gateway restart, no loss/dup | REVIEW+MANUAL | Phase 16 live verification (zero-poll delivery, NATS-only) |
| E1 | AI isolation | `/v1/reply`, `/v1/embed` require internal bearer → 401 without/wrong | EXEC-PASS | live probe (4) |
| E2 | AI isolation | RAG context fenced as DATA (§19) | REVIEW | `main.py:112-114`, `schemas.py:39-40` |
| E3 | AI knowledge | Knowledge docs tenant-scoped (RLS) | EXEC-PASS | Pest knowledge + RLS suite |
| F1 | Attachments | Upload → pending → scanned clean, checksummed, tenant-pathed | EXEC-PASS | Pest + live |
| F2 | Attachments | Flagged upload quarantined; refused download (410) + link (422) | EXEC-PASS | Pest |
| F3 | Attachments | Cross-visitor IDOR (own conv / other's conv) → 404 | EXEC-PASS | live probe (real IDs) |
| F4 | Attachments | Cross-tenant download (Beta agent → Alpha file) → 404 | EXEC-PASS | live probe |
| F5 | Attachments | Signed download URL (no creds); tampered signature → 403 | EXEC-PASS | live probe |
| F6 | Attachments | Disallowed type / oversized rejected (422) | EXEC-PASS | Pest |
| G1 | Error handling | Malformed JSON → 4xx, no stack trace / SQL / path leak | EXEC-PASS | live probe |
| H1 | Accessibility | Widget open panel — no serious axe violations | EXEC-PASS | Playwright+axe |
| H2 | Accessibility | Dashboard login — no serious axe violations (light+dark) | EXEC-PASS | Playwright+axe |
| I1 | Widget E2E | 8 specs incl. hostile-host CSS isolation, reconnect, identity | EXEC-PASS | Playwright |
| J1 | Static | PHPStan (max) clean; Pint clean; composer valid | EXEC-PASS | local |
| J2 | Static | Dashboard tsc + ESLint clean; Vitest 14/14 | EXEC-PASS | local |
| J3 | Static | Widget tsc clean; build ok | EXEC-PASS | local |
| J4 | Static | AI service ruff clean; pytest 15/15 | EXEC-PASS | local |
| J5 | Static | Gateway `mix format --check`, compile `--warnings-as-errors` | EXEC-PASS | local |
| J6 | Security scan | Gitleaks + Semgrep + Trivy | EXEC-PASS (CI) | CI run 30089889943 green on `c911bcb` |
| J7 | Contracts | OpenAPI 3.1 Redocly lint | EXEC-PASS | local |

## B. Defects (see DEFECT_REPORT.md)

| # | Area | Test | Result |
|---|---|---|---|
| K1 | Ops/monitoring | Gateway health/readiness endpoint | EXEC-FAIL (absent — all paths 404) |
| K2 | Test coverage | Cross-browser Firefox/WebKit | EXEC-FAIL (chromium-only config) |
| K3 | Testability | AI service mypy | BLOCKED (not installed) |

## C. Not implemented — Not Available to test (marked, not failed)

MFA · registration/onboarding UI · email verification · password reset · billing/plans/entitlements · super-admin portal · Flow Builder · workflow/Temporal engine · agentic tool-approval tiers (L1–L5) · analytics/Insights/ClickHouse · webhooks · WordPress · WooCommerce · Shopify OAuth · PHP/Laravel/React/Vue/Angular/Flutter/RN SDKs · lead scoring/CRM sync/campaign attribution · offline/ticket flow · business hours · ratings · transcript request · file antivirus engine (only FakeScanner marker) · MFA bypass/session-fixation (no MFA/session cookies to attack — token API only).

## D. Blocked categories

Real-model AI quality/injection · cross-browser · k6 performance · backup/restore · manual screen-reader · full responsive matrix.
