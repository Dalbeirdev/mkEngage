# ADR-006: Data Platform

- **Status:** Accepted (2026-07-23)
- **Date:** 2026-07-23
- **Governing directive:** §9, §10, §11, §12, §13, §14, §29

## Context

The platform stores five distinct data shapes: transactional business records (orgs, users, conversations, messages, approvals, billing); vector + full-text knowledge for RAG; high-volume append-only analytics events; ephemeral coordination state; and binary files. The directive assigns each a specific home and forbids the classic failure modes: analytics queries on the transactional DB (§13), Redis as a system of record (§12), premature vector/search databases (§10, §11).

## Decision

**PostgreSQL (latest stable) is the single transactional system of record** for organizations, users, roles/permissions, contacts, visitors, conversations, messages, chatbots, workflows metadata, knowledge metadata, integrations, subscriptions, feature flags, API keys, webhooks, audit metadata, consent, approvals, and tool executions.

- **Identifiers:** UUIDv7 primary keys everywhere (time-ordered → index-friendly, globally unique → safe cross-service references).
- **Tenancy:** every tenant-owned table carries `organization_id NOT NULL` with **Row-Level Security enforced** in addition to application scopes (ADR-007).
- **JSONB** only for genuinely flexible metadata (widget config, integration settings, event payload snapshots) — never for fields that need relational integrity or indexing-by-value at scale.
- **Partitioning:** `messages`, `visitor_events` (transactional copy where needed), `audit_log`, and outbox tables are **range-partitioned by month** from day one (cheap now, impossible later); partition maintenance is automated (pg_partman or equivalent).
- **Pooling:** PgBouncer (transaction mode) or the managed equivalent in front of Postgres; Octane, Ecto, and asyncpg pool sizes are budgeted against a documented max-connections plan. RLS context uses `SET LOCAL` inside transactions, which is PgBouncer-transaction-mode safe (ADR-007).
- **Recovery:** PITR with encrypted backups; restore drills are part of the ops runbook (ADR-010).

**Vector + hybrid search — pgvector in the same PostgreSQL (§10):** knowledge chunks table with embedding (HNSW index), tsvector (GIN), and the mandated metadata (org, source, document + version, chunk ID, content, URL, title, access scope, publication status, valid-from/expiry, checksum). Hybrid retrieval = pgvector KNN + full-text, fused with Reciprocal Rank Fusion, filtered by tenant/permission/status/freshness (implementation in ADR-003). **No dedicated vector DB and no OpenSearch initially**; either may be added only after a documented benchmark shows Postgres failing the workload (§10, §11), through the `VectorStore`/search adapters that exist from day one.

**ClickHouse is the analytics store (§13):** visitor events, page views, widget interactions, conversation/agent metrics, workflow metrics, AI/token usage and latency, funnel/cart/attribution analytics. Events flow **NATS JetStream → analytics consumer → ClickHouse** (batched inserts, at-least-once with `ReplacingMergeTree`/insert-dedup by event ID). Materialized views maintain per-org daily/hourly aggregates; TTL-based retention per table; all Insights queries carry mandatory org predicates via a query layer (no raw tenant-supplied SQL). PostgreSQL may serve small administrative reports; anything high-volume goes to ClickHouse.

**Redis is ephemeral only (§12):** cache, rate limits, sessions, presence metadata, agent-capacity counters, short-lived tokens/revocations, idempotency caches, locks (with fencing tokens where unavoidable). Namespaced keys `{env}:{org}:{domain}:...`, TTLs mandatory, `maxmemory` + `allkeys-lru`-style eviction configured, TLS + AUTH, HA deployment. **Nothing in Redis is unrecoverable**: flushing Redis must degrade performance, never lose data.

**Object storage is S3-compatible (§14):** uploads, attachments, exports, reports, packages, SDK artifacts, archived conversations, knowledge snapshots. Adapter-based (AWS S3 / Azure Blob / GCS / MinIO locally). Pre-signed, short-expiry upload/download URLs; tenant-prefixed paths (`{org}/...`); content-type and size validation; checksum verification; malware scan + quarantine state before files become servable; SSE encryption; lifecycle rules implement §29 archival (conversation data past active retention moves to object storage).

**Data lifecycle (§29):** per-org configurable retention per data class; legal hold flags override deletion; export and verified hard-deletion run as Temporal workflows (ADR-004) producing auditable completion records including backup-expiration tracking; soft-delete → redaction → hard-delete progression for messages.

## Alternatives considered

1. **Per-service databases.** Cleaner ownership; but forces distributed transactions/queries for core flows (conversation + message + approval), and the team size doesn't support 4 database fleets. Schema-ownership boundaries + per-service DB roles give most of the benefit inside one Postgres.
2. **Dedicated vector DB (Qdrant/Weaviate/Pinecone) now.** Better ANN performance ceilings; another stateful system, another tenancy model to secure, and pgvector demonstrably serves millions of vectors with HNSW. Deferred by §10 pending benchmarks.
3. **OpenSearch for search + analytics.** One engine for both; heavy JVM ops, weaker columnar analytics than ClickHouse, and Postgres FTS covers initial search. Deferred by §11.
4. **TimescaleDB for analytics.** Stays in Postgres; but §13 mandates isolation of analytical load from the transactional DB, and ClickHouse's compression/scan performance at visitor-event volumes is a different class.
5. **Kafka + stream processing for analytics ingest.** Overkill; JetStream + batching consumer meets the volume with a fraction of the ops (ADR-005, §32).

## Advantages

- One transactional engine: ACID across the whole business domain, one backup/restore story, one RLS enforcement point.
- Vectors beside relational data: retrieval filters (tenant, permission, freshness) are SQL predicates, not cross-store joins.
- ClickHouse isolation keeps p99 message-path latency independent of dashboard analytics.
- Every store has an explicit "is not" clause, preventing the drift that creates accidental systems of record.

## Disadvantages

- Shared Postgres is a shared blast radius: a runaway query hurts everyone (mitigated: pooling, statement timeouts, read replicas for reporting later).
- pgvector HNSW index builds are memory-hungry and rebuilds lock semantics need care at large corpus sizes.
- ClickHouse eventual consistency (ingest lag) means Insights is seconds-behind-real-time — acceptable and monitored (freshness metric, §13).
- Operating four stateful systems (PG, CH, Redis, object storage) even if each is managed.

## Security implications

- RLS on every tenant table with automated cross-tenant rejection tests (ADR-007); per-service DB roles scoped to owned schemas/tables (gateway → messages/receipts/outbox only).
- Encryption at rest for all four stores; integration secrets additionally envelope-encrypted at the column level (ADR-009).
- ClickHouse has no RLS: tenant isolation is enforced by the query layer (mandatory org predicate injection) + per-service accounts; tenant-facing query surfaces never accept raw SQL.
- Pre-signed URLs are the only client path to storage; storage credentials never leave services (§14).

## Operational implications

- Managed Postgres/Redis/ClickHouse where available (ADR-010); MinIO + single-node CH + PG in Docker Compose locally.
- Monitor: replication/ingest lag, partition growth, connection-pool saturation, HNSW index size, CH merge backlog, backup success (ADR-008).
- Migrations: control plane owns shared-schema migrations (single migration authority) with gateway-owned tables defined in versioned contract files; expand-and-contract pattern for cross-service schema changes.

## Cost implications

- Managed PG (HA) + managed CH + Redis + storage ≈ the platform's steady infrastructure baseline (low hundreds USD/month small-scale). ClickHouse compression makes analytics storage cheap; partition-drop retention keeps PG bounded; object-storage lifecycle tiers archive cost down.

## Migration path

- Phase 1: single PG (HA pair), single CH node, single Redis, MinIO/S3.
- Growth: PG read replicas (reports) → citus/sharding only if writes demand; CH cluster with replicas; regional stacks per data region (§28) — the region is a property of the org's stack, not a new architecture.
- Vector/search graduation paths exist behind adapters with benchmark gates (§10/§11).

## Reversal strategy

- pgvector → dedicated vector DB: re-embed/export via the `VectorStore` adapter; chunk metadata is the portable contract.
- ClickHouse → alternative OLAP: events are replayable from NATS (bounded window) and re-buildable from PG/object-storage sources; aggregates are derived data by design.
- Redis is flush-safe by decree; object storage is S3-API portable via the adapter. PostgreSQL is the one deliberate, high-commitment choice — reversal is standard dump/restore/logical-replication, and every other component is designed to be more disposable than it.
