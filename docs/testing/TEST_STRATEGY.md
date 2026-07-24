# mkEngage — Test Strategy

**Build under test:** `c911bcb` (main) — "Phase 18 — chat attachments + object storage (§14)"
**Testing period:** 2026-07-24
**Environment:** Local integrated (Windows 11). PostgreSQL 18.4 :5434, NATS 2.10 :4222, Laravel control plane :8000, Phoenix gateway :4000, FastAPI AI service :8100, Next.js dashboard :3100, Lit widget demo :5174, database queue worker, outbox relay.
**Tester role:** Combined senior QA / security / AI-eval / accessibility engineer.

## 1. Critical honesty note — implemented scope vs. the assignment's feature list

The QA assignment describes mkEngage as a full enterprise platform (WordPress/WooCommerce/Shopify, PHP/Laravel/React/Vue/Angular/Flutter/RN SDKs, Flow Builder, workflow/Temporal engine, billing, MFA, super-admin portal, ClickHouse analytics/Insights, webhooks, agentic tool-approval tiers, lead scoring/CRM/campaigns, offline ticketing, ratings/transcripts).

**The repository at `c911bcb` implements the messaging/AI/tenancy core only (build phases 0–18).** The remaining directories (`services/analytics-consumer`, `services/ingestion-worker`, `services/workflow-workers`, `apps/shopify-app`, `apps/docs-site`, `packages/{react,vue,angular,flutter,react-native}-sdk`, `packages/api-client`) are **README-only placeholders** — verified by file inventory (1–2 files each, no source). A control-plane grep for `two_factor|mfa|fortify`, `billing|subscription|cashier`, `super.admin`, `workflow|temporal`, `clickhouse|insights|analytics`, `webhook` returned **0 implementing files**.

Therefore this strategy tests the implemented surface rigorously and marks every unimplemented area **Not Available** (not pass, not fail). No result is fabricated.

## 2. What IS implemented (in scope, testable)

- Multi-tenant control plane (Laravel): org-slug token auth, RBAC + departments, audit log, two-layer tenant isolation (Eloquent fail-closed scope + PostgreSQL RLS `FORCE ROW LEVEL SECURITY`).
- Widget messaging: visitor sessions (site key), conversations, messages (persist-before-confirm, idempotency dedup, per-conversation sequence), signed visitor identity (HMAC).
- Contacts, chatbots (single-active invariant), knowledge base + RAG (hybrid FTS+vector, capability-gated).
- AI service (FastAPI): provider protocol (Fake / Anthropic / OpenAI), internal-bearer auth, `/v1/reply` + `/v1/embed`, §19 context fencing.
- Realtime gateway (Phoenix): HMAC socket tokens, DB-checked channel joins, typing/presence, JetStream fan-out consumer, replay.
- Transactional outbox → NATS JetStream backbone.
- Attachments (§14): upload, finfo type detection, allowlist + size cap, SHA-256, quarantine-first async scanning, signed download URLs.
- Next.js dashboard (agent console) + Lit chat widget.

## 3. Test levels & approach

| Level | Tool | Role |
|---|---|---|
| Static | PHPStan, Pint, tsc, ESLint, ruff, `mix format`/compile, Redocly, Gitleaks/Semgrep/Trivy (CI) | Type/lint/secret/contract gates |
| Unit/Feature | Pest (PHP), ExUnit (Elixir), pytest (Python), Vitest (TS) | Behavior + RLS proof + realtime reliability |
| E2E | Playwright (+ axe-core) | Widget & dashboard journeys, accessibility |
| Live security probes | curl against running services | Auth, cross-tenant IDOR, privilege escalation, error leakage, rate limiting, signed-URL integrity |
| Manual review | Code inspection | AI context fencing, quarantine logic |

## 4. Risk-prioritised order (executed in this order)

1. Authentication → 2. Authorization → 3. Tenant isolation → 4. Realtime reliability → 5. AI data isolation / auth → 6. Attachment security → 7. Static/security scanners → 8. Accessibility → 9. UI.

## 5. Explicitly blocked / not executed (with reason)

| Area | Status | Reason |
|---|---|---|
| Real-model AI quality & prompt-injection resistance | BLOCKED | No Anthropic/OpenAI credentials; only deterministic FakeProvider available. Context-fencing verified by static review only. |
| Cross-browser (Firefox, WebKit) | BLOCKED | Both Playwright configs define `chromium` only. |
| Performance (k6 smoke/load/stress/soak) | NOT AVAILABLE | No k6 scripts in repo; no load-test infrastructure. |
| Backup / restore / RPO-RTO | NOT AVAILABLE | No backup tooling or documented restore procedure. |
| mypy type-check (AI service) | BLOCKED | `mypy` not installed in the service venv. |
| Manual screen-reader, full responsive matrix | PARTIAL | Automated axe-core executed; manual AT passes not performed. |

## 6. Entry / exit criteria

Entry: all 7 services healthy (met). Exit: high-risk categories executed with evidence and an evidence-based verdict recorded in `RELEASE_READINESS.md` (met).
