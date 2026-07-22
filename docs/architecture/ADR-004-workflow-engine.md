# ADR-004: Durable Workflow Engine

- **Status:** Accepted (2026-07-23)
- **Date:** 2026-07-23
- **Governing directive:** §7

## Context

mkEngage runs operations that outlive any single request or process: human approvals (refunds, cancellations) that wait hours or days; appointment and escalation/SLA timers; CRM and integration synchronization with retries; scheduled knowledge ingestion; data export/deletion; subscription lifecycle; multi-step agentic AI tool executions with compensation. The directive forbids modeling these as database status columns + polling, or as basic queue jobs (§7): both lose state on failure, hide progress, and make retries/compensation ad hoc.

## Decision

**Temporal** is the durable workflow engine for every long-running, retryable, or approval-based operation.

**Workers:**
- `services/workflow-workers/php` — Temporal PHP SDK workers for control-plane-domain workflows (subscription lifecycle, data export/deletion, webhook delivery retries, CRM sync) so business logic stays next to its Eloquent models and policies.
- `services/workflow-workers/python` — Python SDK workers for AI-adjacent workflows (agentic tool execution, scheduled ingestion) sharing the AI service's provider/tool code.
- Task queues are named per domain (`billing`, `integrations`, `ai-tools`, `ingestion`, `lifecycle`) so each pool scales and deploys independently.

**Rules (all mandatory):**
- Workflow code is **deterministic**: no I/O, clocks, or randomness in workflow functions; all external effects (DB, network, AI, email/SMS, Shopify/WordPress/CRM calls, file processing, webhook delivery) live in **activities**.
- Every activity declares an explicit **retry policy** (exponential backoff, max attempts), **timeouts** (schedule-to-close, start-to-close), and **heartbeats** for long activities; all mutating activities take **idempotency keys**.
- Multi-step external mutations define **compensation steps** (saga pattern) — e.g., refund workflow: reserve → approve (human signal) → execute → notify, with reversal activities on failure.
- **Human approvals** are Temporal **signals**: the workflow parks on `await approval`, the control plane records the decision (audit + RLS) and signals the workflow; timers escalate or expire stale approvals.
- Workflows carry **search attributes** (org ID, workflow type, correlation ID, subject entity) for operational queries, and emit lifecycle events (`workflow.started/completed/failed`) to NATS (ADR-005).
- **Versioning:** `workflow.patched()`/versioned branches for in-flight compatibility; workflow definitions are semantically versioned; breaking changes drain on old queues.
- Workflows are started **via the outbox** (ADR-005) or idempotent `signalWithStart` using business-key workflow IDs (`refund-{org}-{orderId}`) to prevent duplicates.

**Boundary with LangGraph (§6):** LangGraph decides *what* an agent does next within a conversation; the moment an action is long-running, approval-gated, or externally mutating, it becomes a Temporal workflow/activity. LangGraph never sleeps on timers or owns retries of external systems.

**Deployment:** managed Temporal Cloud where acceptable, or self-hosted Temporal on Kubernetes (Helm) with PostgreSQL persistence for regulated/white-label deployments (ADR-010). Local development uses the Temporal dev server in Docker Compose.

## Alternatives considered

1. **Laravel queues + status columns + scheduler polling.** Zero new infrastructure; loses in-flight state on crashes, no signals/timers as first-class citizens, retries and compensation hand-rolled per feature. Explicitly forbidden (§7).
2. **BullMQ/Sidekiq-style queue orchestration.** Same fundamental gap: queues move tasks, they don't own long-lived state machines. Forbidden (§7).
3. **AWS Step Functions.** Managed and durable; JSON-DSL authoring at our workflow complexity is painful, vendor lock-in conflicts with white-label/self-host and multi-region flexibility (§28).
4. **Inngest / Trigger.dev.** Nice DX; younger ecosystems, weaker PHP story, less proven at enterprise/self-hosted scale.
5. **Elixir Oban + gen_statem.** Fits the gateway stack; would concentrate business workflow logic in the language fewest team members write, and rebuilds Temporal's replay/versioning/visibility from parts.

## Advantages

- Crash-proof state: workflows resume exactly where they were, across deploys and node failures.
- Approvals, timers, retries, sagas, and versioned migrations are engine primitives, not custom code.
- Full execution history per workflow = built-in audit trail for approval-based actions.
- Polyglot SDKs let each workflow live in the language of its domain.

## Disadvantages

- A significant new infrastructure component with its own persistence, upgrades, and failure modes.
- Determinism discipline is unfamiliar; a non-deterministic slip breaks replay (mitigated by replay tests in CI and static checks).
- Two worker runtimes to operate (PHP + Python).
- Temporal Cloud is a recurring cost; self-hosting is real ops work.

## Security implications

- Workflow inputs/outputs may contain tenant data: Temporal payloads use a **data converter with AES-GCM encryption** (keys per environment, ADR-009) so the Temporal cluster/cloud never sees plaintext.
- Org ID is a mandatory search attribute; workers resolve tenant context per activity and run DB work under RLS (ADR-007).
- Approval signals are accepted only from the control plane after policy checks (ADR-009); the approval record (who/when/what) is written before the signal is sent.
- Task-queue-scoped worker identities; workers hold only the secrets their queue's activities need.

## Operational implications

- Dashboards: workflow success/failure rates, activity retry storms, task-queue backlog and schedule-to-start latency, worker fleet health (ADR-008).
- Runbooks: stuck-workflow triage (query by search attributes), version-drain procedure, replaying failed activities, DLQ-equivalent handling via failed-workflow queries.
- Temporal upgrades are scheduled maintenance with worker compatibility checks.

## Cost implications

- Temporal Cloud: per-action pricing — predictable, scales with workflow volume; likely low hundreds USD/month initially. Self-hosted: ~3 small nodes + Postgres, plus engineer time. Decision point recorded in ADR-010; either is cheaper than one data-loss incident in refund handling.

## Migration path

- Phase 1: `billing`, `integrations`, `ai-tools` queues with the refund-approval workflow as the reference implementation (signal + timer + compensation + audit).
- Later: migrate any interim queue-based jobs into workflows queue-by-queue; multi-region task queues per data region (§28).

## Reversal strategy

- Workflow *definitions* encode business processes explicitly (steps, retries, compensations) — that specification transfers to any successor engine.
- Activities are plain functions with idempotency keys; only the orchestration shell is Temporal-specific.
- Reversal = stand up successor, dual-run new workflow starts on it, drain in-flight Temporal executions to completion, decommission. No data migration required because business state lives in PostgreSQL, not in Temporal histories.
