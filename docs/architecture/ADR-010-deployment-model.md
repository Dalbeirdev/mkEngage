# ADR-010: Deployment Model

- **Status:** Accepted (2026-07-23)
- **Date:** 2026-07-23
- **Governing directive:** §24, §28, §32

## Context

The platform comprises six deployable units plus four stateful dependencies (PostgreSQL, Redis, NATS, ClickHouse), Temporal, object storage, and the observability stack. It must run three ways: on a developer's laptop (fast, no Kubernetes — §24), in shared staging, and in production with HA, autoscaling, and eventually multiple data regions (§28) including white-label deployments. Deployment history must be declarative and auditable.

## Decision

**Local development — Docker Compose only (§24):** one `docker compose up` brings up Postgres (+pgvector), Redis, NATS JetStream, ClickHouse, MinIO, Temporal dev server, the OTel mini-stack, and Mailpit. Application services run natively (Octane watch, `mix phx.server`, Uvicorn `--reload`, `next dev`) against the composed infrastructure for fast feedback; a full-compose profile also containerizes the apps for integration parity. Kubernetes is never required locally.

**Production — Kubernetes:**
- **Images:** multi-stage Dockerfiles per service, distroless/slim final layers, non-root, SBOM + Trivy scan in CI; images tagged by git SHA, never `latest`.
- **Packaging:** Helm charts per service under `infrastructure/helm/`, with an umbrella chart per environment; values files per environment/region are the single source of deployment truth.
- **GitOps:** Argo CD (or equivalent) syncs the cluster from the repo — no imperative `kubectl apply` to production; rollback = git revert; drift is detected and reconciled.
- **Provisioning:** Terraform under `infrastructure/terraform/` for clusters, managed databases, DNS, edge configuration, KMS, buckets — reviewed like code.
- **Mandatory workload hygiene (§24):** ≥2 replicas for every stateless service, PodDisruptionBudgets, resource requests *and* limits, HPA (CPU + custom metrics: socket count for the gateway, NATS consumer lag for workers, task-queue latency for Temporal workers), readiness/liveness/startup probes, rolling deploys, availability-zone spread constraints, NetworkPolicies default-deny, restricted Pod Security Standards, secrets via External Secrets/CSI injection from KMS/Vault (never in manifests or images — §18).
- **Stateful services:** managed offerings preferred (managed Postgres, Redis, ClickHouse Cloud, Temporal Cloud, S3) — the team's scarce resource is ops attention. Self-hosted Helm variants are maintained for white-label/regulated deployments; the umbrella chart treats managed-vs-self-hosted as a values switch behind identical connection contracts.
- **Migrations:** run as pre-sync Jobs (Argo hooks) with expand-and-contract discipline (ADR-006); a failed migration blocks the rollout, and CI rehearses migrations against a production-schema copy (§26).

**Environments:** `local → preview (per-PR, ephemeral namespace with seeded data) → staging (production-shaped, shared) → production`. Promotion is by image digest + values change through GitOps; no environment-specific builds.

**Multi-region (§28) — home-region model:** each region is a self-contained stack (K8s + PG + Redis + NATS + CH + storage + AI endpoints + backups) deployed from the same charts with region values. A thin **global layer** holds only authentication routing, org→region resolution, subscription status, and feature entitlement (ADR-007). Orgs live in exactly one region; no active-active writes initially (§28). Region launch = Terraform workspace + values file, not new architecture. Initial regions: US first; EU next (data-residency demand predictor); UK/India/Australia as sales requires.

**DR/backup (§24):** PITR for Postgres, scheduled CH backups, object-storage versioning + cross-zone replication, NATS stream snapshots for bounded replay; documented RPO ≤ 15 min / RTO ≤ 4 h initially; restore drills quarterly; runbooks in `docs/`.

**Initial deployment profile (§32):** Laravel control plane, Next.js dashboard, Phoenix gateway, Python AI + ingestion workers, Temporal, NATS JetStream, PostgreSQL+pgvector, Redis, ClickHouse, S3-compatible storage, OTel Collector. **No OpenSearch, no Kafka** — each requires a documented benchmark/functional case before introduction (§11, §32).

## Alternatives considered

1. **PaaS (Fly.io/Render/Railway) for everything.** Fastest start; multi-service + Temporal + NATS + ClickHouse + NetworkPolicies + white-label regional stacks outgrow PaaS constraints quickly, and migration later is a full re-platform. Rejected for production; fine for throwaway previews if ever useful.
2. **ECS/Fargate.** Less cluster ops than K8s; AWS lock-in conflicts with white-label/self-host and §28 regional flexibility; Helm/Argo ecosystem (mandated) is K8s-native.
3. **Nomad.** Simpler orchestrator; ecosystem (Helm, Argo, operators for NATS/CH/Temporal) is decisively K8s; hiring likewise.
4. **Push-based CD (CI runs kubectl/helm).** Simpler mental model; loses drift detection, audit trail, and easy rollback that GitOps gives; credentials for prod live in CI (a §18 smell). Rejected.
5. **Self-hosting all stateful services from day one.** Cheaper on paper; ops attention is the scarcest resource and databases are where outages become data loss. Managed-first with self-hosted variants maintained is the deliberate middle.

## Advantages

- Laptop-to-production uses the same images and (per-service) the same charts — parity kills "works locally" bugs.
- GitOps: every production change is a reviewed commit; rollback is `git revert`; auditors read history for free.
- Region-as-values makes §28 and white-label deployments a repeatable procedure, not a project.
- Managed-first keeps the six-service platform operable by a small team; self-hosted variants keep enterprise/white-label deals open.

## Disadvantages

- Kubernetes + Helm + Argo + Terraform is a serious learning curve and standing complexity.
- Maintaining both managed and self-hosted values paths doubles some testing surface.
- Per-PR preview environments cost CI time and cluster capacity (bounded by TTL cleanup).
- Managed services constrain versions/extensions (pgvector availability must be verified per provider — a selection criterion, not an afterthought).

## Security implications

- Default-deny NetworkPolicies, restricted PSS, non-root distroless images, image signing + SBOM, secrets injected at runtime from KMS/Vault (ADR-009).
- Argo CD is a high-privilege component: SSO-gated, RBAC-scoped per project, its repo credentials read-only.
- Terraform state contains sensitive values: encrypted remote state, access-controlled, no secrets in plain variables.
- Per-region isolation limits blast radius; global layer holds no tenant content (ADR-007).

## Operational implications

- On-call runs on the Grafana/Alertmanager stack (ADR-008) with runbooks per service; deploys are boring by design (rolling, probed, PDB-protected, auto-rollback on failed health).
- Cluster upgrades quarterly; chart/dependency updates via Renovate PRs.
- Capacity: HPA handles daily cycles; regional stacks are sized independently from real per-region metrics.

## Cost implications

- Production baseline (single region, managed stateful): roughly $500–1,500/month at launch scale — control-plane nodes for 6 services ×2 replicas, HA Postgres, small Redis/NATS/CH, Temporal Cloud entry tier, edge, observability storage. Each additional region duplicates the stack (the white-label/residency price is explicit). Preview environments add marginal CI/cluster cost with TTL cleanup.

## Migration path

- Phase 1: Compose local + single K8s staging/production in the US region, GitOps from day one (staging first, production as soon as the first deployable service exists).
- Phase 2: per-PR previews, DR drills, self-hosted values variants exercised in CI.
- Phase 3+: EU region via the region procedure; further regions on demand (§28).

## Reversal strategy

- Everything is containers + declarative manifests: moving to a different orchestrator or a future platform means re-targeting charts/Terraform — application code is untouched.
- Managed↔self-hosted for any stateful dependency is a values switch by design.
- GitOps history preserves every prior working state; the fastest reversal of any bad decision in this ADR is a revert commit.
