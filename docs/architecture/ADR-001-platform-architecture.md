# ADR-001: Platform Architecture

- **Status:** Accepted (2026-07-23)
- **Date:** 2026-07-23
- **Deciders:** mkEngage engineering
- **Governing directive:** mkEngage Advanced Technology and Architecture Directive §1, §30, §31, §32

## Context

mkEngage is a multi-tenant, white-label, omnichannel chatbot and customer-engagement SaaS. It must sustain large numbers of concurrent WebSocket connections, real-time bidirectional messaging, AI knowledge retrieval (RAG), agentic AI actions with human approvals, long-running workflows, high-volume visitor event ingestion, and real-time analytics — while integrating with web, mobile, WordPress, WooCommerce, Shopify, PHP, and Laravel host applications.

These workloads have fundamentally different scaling characteristics:

| Workload | Character |
|---|---|
| Admin/CRUD/billing | Low-frequency, transactional, consistency-critical |
| Real-time messaging | Massive fan-out, connection-heavy, latency-critical |
| AI inference + RAG | Bursty, slow (seconds), external-provider-bound |
| Document ingestion | Batch, CPU/IO heavy, untrusted input |
| Workflows/approvals | Long-running (minutes to weeks), must survive restarts |
| Analytics | Append-heavy writes, columnar scans |

A single framework cannot serve all six well. Equally, splitting every feature into a microservice would create a distributed monolith with high operational cost for a small team.

## Decision

Adopt a **modular platform of six deployable units**, split only along scaling-characteristic boundaries:

1. **SaaS control plane** — Laravel (Octane on FrankenPHP). Modular monolith: auth, tenancy, users/roles, billing, configuration, administration, REST API management. (ADR details in this document; stack per directive §2.)
2. **Real-time messaging gateway** — Elixir/Phoenix. WebSockets, presence, fan-out, message ingest hot path. (ADR-002)
3. **AI and RAG service** — Python/FastAPI + LangGraph. Model routing, retrieval, agent tool selection. (ADR-003)
4. **Durable workflow workers** — Temporal workers (PHP + Python workers as appropriate). Long-running and approval-based operations. (ADR-004)
5. **Knowledge-ingestion workers** — Python. Crawling, extraction, chunking, embedding, in isolated containers. (ADR-003, ADR-009)
6. **Analytics pipeline** — NATS JetStream consumer writing to ClickHouse; queried by mkEngage Insights. (ADR-005, ADR-006)

Plus two frontend applications: the **Next.js dashboard** and the **Lit/Web-Components chat widget** (a static asset, not a service).

Services communicate through:
- **NATS JetStream** for asynchronous operational events (ADR-005) — the default.
- **Authenticated internal HTTP** only where a synchronous answer is unavoidable (e.g., gateway token introspection fallback, AI service invocation).
- **PostgreSQL as the single transactional system of record** (ADR-006); no service-private databases in the initial release, with schema-ownership boundaries per service enforced by convention and separate DB roles.

The repository is a **monorepo** following the directive §31 layout (`apps/`, `services/`, `packages/`, `integrations/`, `contracts/`, `infrastructure/`, `tests/`, `docs/`), with contracts (`OpenAPI`, `AsyncAPI`, JSON Schema events) as the source of truth between units.

## Alternatives considered

1. **Single Laravel monolith (Reverb for WebSockets, queues for everything).** Simplest to build; fails the concurrency requirement — PHP's process-per-connection economics make hundreds of thousands of sockets impractical, and long-running approval workflows in queue jobs are fragile. Explicitly forbidden by directive §2/§30.
2. **Full microservices (15–25 services).** Maximum independent scaling; unaffordable operational burden, distributed-monolith risk, and premature boundaries before the domain is proven. Forbidden by directive §30.
3. **Node.js everywhere (NestJS + Socket.IO + BullMQ).** One language; but inferior to BEAM for connection supervision/presence, inferior to Temporal for durable workflows, and contradicts the directive's mandated stacks.
4. **Serverless-first (Lambda/functions + managed WebSockets).** Low idle cost; poor fit for persistent WebSocket state, Temporal workers, and self-hostable white-label deployments.

## Advantages

- Each unit uses the runtime best suited to its workload (BEAM for sockets, Python for AI, PHP for product/billing velocity, Temporal for durability).
- Only six deployables: tractable for a small team, still horizontally scalable per unit.
- Contract-first boundaries (OpenAPI/AsyncAPI/JSON Schema) keep units replaceable and independently testable.
- Monorepo gives atomic cross-service changes, shared CI, and one place for contracts.

## Disadvantages

- Four languages (PHP, TypeScript, Elixir, Python) raise hiring and context-switching cost.
- Cross-service consistency requires event-driven patterns (outbox/inbox, idempotency) from day one.
- Shared PostgreSQL requires discipline (schema ownership, RLS) to avoid hidden coupling.
- Local development needs Docker Compose orchestration of ~10 processes.

## Security implications

- Every boundary is an authorization boundary: tenant context must be revalidated at the gateway, control plane, AI service, and database (RLS) — no service trusts another's claims without verification (ADR-007, ADR-009).
- Smaller attack surface per unit; the ingestion workers (highest-risk input) are isolated in their own containers with strict egress controls.
- Internal HTTP requires mutual authentication (network policy + service tokens); nothing is reachable except through the API gateway/edge (ADR-009).

## Operational implications

- Kubernetes in production with per-unit autoscaling (ADR-010); Docker Compose locally.
- Six deployables × (health checks, dashboards, alerts, runbooks) — bounded but real overhead.
- On-call must understand event-flow debugging (correlation IDs end-to-end, ADR-008).

## Cost implications

- Baseline production footprint ≈ 6 small service deployments + Postgres + Redis + NATS + ClickHouse + Temporal + object storage. Modest (low hundreds of USD/month at small scale on managed services); scales linearly with the unit that needs it rather than the whole platform.
- Biggest cost lever is AI provider spend, governed by budgets in ADR-003.

## Migration path

- From nothing → this architecture is greenfield; build order per directive §32 (control plane, dashboard, gateway, AI/ingestion workers, Temporal, NATS, Postgres+pgvector, Redis, ClickHouse, S3, OTel).
- Future extraction: any control-plane module (e.g., billing) can be extracted later because module boundaries + contracts already exist.
- Future consolidation: analytics consumer may fold into ingestion workers if volume stays low.

## Reversal strategy

- If polyglot cost proves too high: the AI service and ingestion workers merge naturally (both Python); the gateway is the only Elixir unit and could be replaced by a managed WebSocket provider behind its existing channel contract — clients speak a documented protocol, not Phoenix specifics.
- If the modular monolith fractures poorly: boundaries are contract-defined, so re-partitioning services is a deployment change, not a rewrite.
- Sunk cost on reversal is bounded to the replaced unit; contracts and data model survive.
