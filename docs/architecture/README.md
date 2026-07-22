# mkEngage Architecture Decision Records

Authoritative source: **mkEngage Advanced Technology and Architecture Directive** (repo root PDF). Each ADR contains the ten mandated sections: Context, Decision, Alternatives considered, Advantages, Disadvantages, Security implications, Operational implications, Cost implications, Migration path, Reversal strategy.

| ADR | Title | Core decision |
|---|---|---|
| [ADR-001](ADR-001-platform-architecture.md) | Platform architecture | Six deployable units split by scaling characteristics; monorepo; contract-first boundaries |
| [ADR-002](ADR-002-realtime-gateway.md) | Real-time gateway | Elixir/Phoenix; persist-before-confirm ingest transaction inside the gateway; signed short-lived socket tokens |
| [ADR-003](ADR-003-ai-service.md) | AI and RAG service | Python/FastAPI; provider + vector-store adapters; LangGraph for agent state only; sandboxed ingestion |
| [ADR-004](ADR-004-workflow-engine.md) | Workflow engine | Temporal for all long-running/approval/retryable operations; PHP + Python workers; signals for approvals |
| [ADR-005](ADR-005-event-backbone.md) | Event backbone | NATS JetStream; CloudEvents envelopes + JSON Schema; outbox/inbox everywhere; no Kafka initially |
| [ADR-006](ADR-006-data-platform.md) | Data platform | PostgreSQL system of record + pgvector hybrid search; ClickHouse analytics; Redis ephemeral-only; S3 adapter |
| [ADR-007](ADR-007-multi-tenancy.md) | Multi-tenancy | Shared schema + forced RLS with `SET LOCAL` transaction context; generated cross-tenant rejection tests; home-region model |
| [ADR-008](ADR-008-observability.md) | Observability | OpenTelemetry everywhere → Collector → Tempo/Prometheus/Loki/Grafana + Sentry-compatible; allow-listed telemetry |
| [ADR-009](ADR-009-security-boundaries.md) | Security boundaries | Nine explicit trust boundaries, fail-closed authorization, envelope-encrypted secrets, sandboxed ingestion |
| [ADR-010](ADR-010-deployment-model.md) | Deployment model | Docker Compose local; K8s + Helm + Terraform + GitOps production; managed-first stateful; region-as-values |

Domain inferences pending the product specification: [ASSUMPTIONS.md](ASSUMPTIONS.md).

## Status

All ADRs are **Accepted** (2026-07-23). Current phase: monorepo skeleton (§31) + OpenAPI/AsyncAPI/event contracts, then Phase 1.
