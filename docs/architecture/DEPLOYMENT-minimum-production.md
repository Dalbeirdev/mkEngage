# Minimum Production Deployment

The smallest deployment that may be called "production" (directive §32, ADR-010). Anything less is a demo.

## Deployable units (all required)

| Unit | Replicas (min) | Scaling signal |
|---|---|---|
| Control plane (Laravel Octane) | 2 | CPU / request latency |
| Dashboard (Next.js) | 2 | CPU |
| Real-time gateway (Phoenix, clustered) | 2 | socket count |
| AI service (FastAPI) | 2 | request concurrency |
| Ingestion worker | 1 (sandboxed pool) | queue depth |
| Workflow workers (php, python) | 1 each | task-queue schedule-to-start latency |
| Analytics consumer | 1 | NATS consumer lag |

## Stateful dependencies (all required)

- **PostgreSQL** (latest stable, pgvector) — HA (primary + replica), PITR enabled, encrypted backups, PgBouncer in front
- **Redis** — HA, TLS + AUTH, maxmemory + eviction configured
- **NATS JetStream** — 3-node cluster, streams + DLQs from `contracts/asyncapi/nats-events.yaml`
- **ClickHouse** — single node acceptable initially, scheduled backups
- **Temporal** — Temporal Cloud or self-hosted with Postgres persistence
- **S3-compatible object storage** — versioning, lifecycle rules, SSE
- **OpenTelemetry Collector** → Tempo / Prometheus / Loki / Grafana + Sentry-compatible tracker

Explicitly **excluded** until benchmarks/documented need: OpenSearch, Kafka, dedicated vector DB, OpenFGA (§10, §11, §16, §32).

## Gate checklist — a unit is production-ready only when all boxes tick (§33)

- [ ] Tests green in CI (unit, integration, contract) and type/static analysis at required strictness
- [ ] Security scans clean of criticals (Trivy, Semgrep, Gitleaks; ZAP for exposed surfaces)
- [ ] Readiness, liveness, and startup probes implemented and exercised
- [ ] OTel traces/metrics/logs flowing with correlation IDs; service dashboards + SLO alerts provisioned
- [ ] Failure handling per RULES-failure-retry (timeouts, retries, DLQ/idempotency) demonstrated by test
- [ ] RLS/tenant tests passing (RULES-tenant-isolation) for any unit touching tenant data
- [ ] Helm chart with ≥2 replicas (where applicable), PDB, resource requests/limits, HPA, NetworkPolicies, restricted PSS
- [ ] Secrets injected from KMS/Vault; none in images, manifests, or CI files (§18)
- [ ] Rolling deploy + rollback rehearsed via GitOps; migration job tested against a production-schema copy
- [ ] Runbook: start/stop, common failures, DLQ drain, restore procedure

## Edge (required before public traffic)

Cloudflare (or equivalent): TLS, WAF, DDoS, bot management, rate limits, cache-control tiers per ADR-009; custom-domain support for white-label.

## Region

Single home region (US) initially; the entire stack above is region-reproducible via Terraform workspace + Helm values (§28, ADR-010).
