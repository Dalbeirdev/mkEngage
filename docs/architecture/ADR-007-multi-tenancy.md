# ADR-007: Multi-Tenancy and Tenant Isolation

- **Status:** Accepted (2026-07-23)
- **Date:** 2026-07-23
- **Governing directive:** §9, §16, §28

## Context

mkEngage is multi-tenant at every layer: shared application processes, shared PostgreSQL, shared NATS, shared ClickHouse, shared object storage — serving organizations that include white-label resellers and enterprises with regional data requirements. A tenant-isolation failure is an existential incident. The directive requires **two independent enforcement layers** (§9): application-level organization scoping **and** PostgreSQL Row-Level Security, with transaction-scoped tenant context and automated tests proving the database rejects cross-tenant access. Application scopes must not be the only control.

## Decision

**Tenancy model:** single shared schema, `organization_id` (UUIDv7) on every tenant-owned row. Organizations are the isolation unit; departments/teams are intra-org structures handled by authorization (ADR-009), not by isolation machinery. Each organization has a **home data region** (§28); an org's rows live only in its region's stack.

**Layer 1 — application scoping (all services):**
- Laravel: tenant context resolved once per request (from Sanctum token/API key/session), carried in a request-scoped `TenantContext`; a mandatory global scope + `BelongsToOrganization` trait on every tenant model; Octane-safe (context reset between requests); relation and query-builder escapes are lint-blocked (Pint/PHPStan custom rules flag `withoutGlobalScope` outside vetted namespaces).
- Phoenix: org ID from the socket token, pinned in channel state; every Ecto query composed through a `TenantQuery` module requiring an org.
- Python: org ID from the authenticated internal call; repository layer requires `org_id` as a non-optional argument.

**Layer 2 — PostgreSQL Row-Level Security (the backstop):**
- Every tenant table: `ALTER TABLE ... ENABLE ROW LEVEL SECURITY; FORCE ROW LEVEL SECURITY;` with policy `USING (organization_id = current_setting('app.current_org_id')::uuid)` (and equivalent `WITH CHECK`).
- **Transaction-scoped context:** services run tenant work inside a transaction that first executes `SET LOCAL app.current_org_id = $1` — safe with PgBouncer transaction pooling because `SET LOCAL` dies with the transaction; a connection can never leak context to the next tenant.
- Application DB roles are **non-superuser, non-`BYPASSRLS`, and do not own the tables** (owners bypass RLS-unless-forced; we force RLS anyway — belt and braces). Migration role is separate and unused at runtime.
- Cross-tenant/administrative operations (super-admin, billing aggregation, platform analytics) use an explicitly named `platform_service` role with its own RLS policies and mandatory audit logging — never by disabling RLS on the tenant role.

**Beyond PostgreSQL:**
- **Real-time:** channel topics embed org ID (`org:{org}:conv:{id}`); the gateway authorizes joins against token org + conversation membership; PubSub broadcasts are topic-isolated (ADR-002).
- **Events:** org ID is a mandatory envelope extension (ADR-005); consumers set tenant context from the envelope before touching the DB, and inbox processing runs under that org's RLS context.
- **ClickHouse:** no RLS — the Insights query layer injects a mandatory org predicate from an allow-listed parameterization; tenant-facing surfaces never accept raw SQL (ADR-006).
- **Object storage:** tenant-prefixed keys, pre-signed URLs generated only after app-layer authorization; no listing across prefixes (ADR-006).
- **Redis:** org-namespaced keys; no cross-org scans in application code.
- **Caches/queues:** cache keys and Horizon job payloads carry org ID; job handlers re-establish tenant context — background work never inherits ambient context.
- **AI/RAG:** retrieval runs under RLS *and* repeats the org filter in the query (defense in depth); embeddings/citations never cross orgs (ADR-003).

**Required tests (CI-blocking, §9):**
1. SQL-level: for every tenant table, attempts to `SELECT/INSERT/UPDATE/DELETE` rows of org B under org A's context return zero rows / RLS errors (generated per-table from the schema catalog, so new tables are covered automatically — a table without a policy fails the suite).
2. Missing-context test: queries without `app.current_org_id` set return nothing (fail closed), not everything.
3. API-level: authenticated cross-tenant probes against every REST resource (404, never 403-with-existence-leak).
4. Channel-level: cross-org join and subscription attempts rejected (gateway test suite).
5. Retrieval-level: RAG queries never return chunks from another org (AI service suite with adversarial fixtures).

**White-label:** resellers are organizations with child organizations; isolation between reseller children is identical to any two tenants (no shared-context shortcuts); reseller-level views are explicit aggregation endpoints under the audited platform role.

## Alternatives considered

1. **Application scopes only.** Simplest; a single forgotten scope or raw query is a breach. Forbidden by §9.
2. **Schema-per-tenant.** Strong isolation; collapses at thousands of tenants (migration fan-out, connection/catalog bloat, pgvector index-per-schema waste) and complicates cross-tenant platform operations.
3. **Database-per-tenant.** Strongest isolation, viable for a handful of enterprise tenants; operationally impossible as the default at SaaS scale. Kept as a **future premium option** the region-aware design already accommodates (an org's "region stack" could be dedicated).
4. **RLS via session variables without `SET LOCAL` (SET at connect).** Breaks under transaction pooling (context leaks across pooled transactions). Rejected on correctness.
5. **Citus/row-sharding by tenant now.** Premature; partitioning + region stacks cover the foreseeable scale (ADR-006).

## Advantages

- Two independent layers: an application bug must coincide with a missing/incorrect DB policy to leak data.
- `SET LOCAL` + forced RLS is pooler-safe and covers every access path — ORMs, raw SQL, future services.
- Auto-generated per-table tests turn "did we remember RLS?" into a CI failure instead of an incident.
- Region-as-org-property makes §28 an infrastructure exercise, not a re-architecture.

## Disadvantages

- RLS predicate evaluation adds small per-query overhead (single indexed equality — benchmarked, typically negligible).
- Every service must wrap tenant work in context-setting transactions — a discipline enforced by shared DB libraries per language (`packages/` + service equivalents), which we must build first.
- Platform-wide operations become deliberately harder (audited role, explicit policies) — accepted friction.
- ClickHouse and Redis isolation remains application-enforced (no RLS equivalent) — concentrated in one query layer each to keep the audit surface small.

## Security implications

- This ADR *is* the core security control. Residual risks: policy misconfiguration (covered by generated tests), the `platform_service` role (covered by audit logging + least privilege + separate credentials), and non-Postgres stores (covered by single-choke-point query layers).
- Org existence must not leak: cross-tenant probes return the same 404/timing behavior as nonexistent resources.
- Token org claims are signed and short-lived (ADR-002/009); org context is never accepted from client-supplied IDs without verification against the authenticated principal.

## Operational implications

- Migrations must ship the RLS policy in the same migration as the table (template/generator enforces it).
- Incident tooling: platform-role queries are logged with operator identity and reason; break-glass procedure documented.
- Region stacks (§28): global control layer stores only routing metadata (org → region, auth routing, subscription status, entitlements); everything tenant-owned stays in-region, including backups.

## Cost implications

- Near-zero infrastructure cost; the spend is engineering discipline and the shared context libraries — paid once, amortized everywhere. Compare with the alternative: cross-tenant breach = existential.

## Migration path

- Phase 1: shared schema + RLS in the primary region, generated test suite, context libraries for PHP/Elixir/Python.
- Growth: additional region stacks (§28 home-region model); dedicated stacks for premium tenants; partitioning already tenant-aware (ADR-006).

## Reversal strategy

- Shared-schema → schema/DB-per-tenant: `organization_id` on every row makes per-tenant extraction a filtered dump; RLS policies drop cleanly.
- RLS itself is additive — disabling it (never planned) is a policy drop, not a data change.
- The tenancy contract (org on every row, context per transaction) is the invariant all reversals preserve; nothing in the platform assumes shared-schema specifics beyond the context libraries.
