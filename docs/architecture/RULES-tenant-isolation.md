# Platform Rules: Tenant Isolation

Binding rules for every service. Derived from ADR-007 (mechanics) and ADR-009 (boundaries). Violations are CI failures, not review comments.

## The invariant

**No code path may read or write tenant-owned data without an authenticated organization context, enforced at both the application layer and PostgreSQL RLS.**

## Rules

1. **Every tenant-owned table** has `organization_id UUID NOT NULL`, `ENABLE ROW LEVEL SECURITY`, `FORCE ROW LEVEL SECURITY`, and policies checking `organization_id = current_setting('app.current_org_id')::uuid` for `USING` **and** `WITH CHECK`. The migration that creates a table ships its RLS policy in the same migration.
2. **All tenant DB work runs inside a transaction** that first executes `SET LOCAL app.current_org_id = $1`. Never `SET` without `LOCAL` (leaks across pooled connections). Services use the shared tenant-context library for their language — direct connection handling in feature code is forbidden.
3. **Runtime DB roles** are non-superuser, non-`BYPASSRLS`, and never own tenant tables. The migration role is separate and unused at runtime. Per-service roles are limited to their owned tables (e.g., gateway → messages, sequences, receipts, gateway outbox).
4. **Org context comes only from verified credentials** — Sanctum token, API key, signed socket token, SCIM-provisioned session, or event envelope written by a trusted producer. Client-supplied org IDs in URLs/bodies are never trusted; they are compared against the authenticated context and rejected on mismatch with **404** (not 403 — existence must not leak).
5. **Cross-tenant platform operations** (super-admin, billing aggregation) run under the `platform_service` role with its own policies, mandatory audit-log entries (actor, reason, scope), and never by disabling RLS.
6. **Background work re-establishes context.** Queue jobs, Temporal activities, and NATS consumers carry `organization_id` in their payload/envelope and set tenant context before any DB call. Ambient context inheritance is forbidden.
7. **Non-Postgres stores:**
   - ClickHouse: every tenant-facing query goes through the Insights query layer, which injects the org predicate; raw SQL from tenant paths is forbidden.
   - Redis: keys are namespaced `{env}:{org}:...`; cross-org `SCAN`/`KEYS` in application code is forbidden.
   - Object storage: keys are prefixed `{org}/...`; access only via pre-signed URLs issued after app-layer authorization; bucket listing is never tenant-reachable.
   - NATS: `orgid` is a mandatory envelope extension; consumers scope all processing to it.
8. **Real-time:** channel topics embed the org (`org:{org}:...`); joins re-verify conversation access server-side; PubSub broadcasts never cross org topics.
9. **AI/RAG:** retrieval queries run under RLS **and** repeat the org filter explicitly. Embeddings, citations, checkpoints, and prompt logs are org-scoped rows like any other.
10. **Telemetry:** org IDs appear only in protected internal telemetry (ADR-008); never in tenant-facing responses, error messages, or public metrics.

## Required tests (CI-blocking)

- **Generated per-table RLS suite:** for every table with `organization_id`, prove org-A context cannot `SELECT/INSERT/UPDATE/DELETE` org-B rows. A tenant table without a policy fails the suite by construction.
- **Fail-closed test:** with no `app.current_org_id` set, tenant queries return zero rows/error — never all rows.
- **API probe suite:** authenticated cross-tenant requests against every resource return 404 with no timing/existence leak.
- **Channel suite:** cross-org join, subscribe, and publish attempts are rejected and logged.
- **Retrieval suite:** adversarial fixtures prove RAG never returns cross-org chunks.
