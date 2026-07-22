# ADR-005: Event Backbone

- **Status:** Accepted (2026-07-23)
- **Date:** 2026-07-23
- **Governing directive:** §8, §27, §32

## Context

Six deployable units must react to each other's facts (message accepted, agent assigned, knowledge ingested, workflow completed, usage limit reached) without synchronous coupling, and the analytics pipeline needs a high-volume stream into ClickHouse. The directive mandates NATS JetStream (§8), forbids Kafka initially (§32), and requires at-least-once semantics with idempotent consumers, outbox/inbox patterns, dead-letter handling, and replay.

## Decision

**NATS with JetStream** is the sole operational event backbone.

**Streams (initial):**

| Stream | Subjects | Retention |
|---|---|---|
| `CONVERSATIONS` | `conv.>` (created, message.accepted/delivered/read, agent.assigned, transferred, closed) | 7 days, work-queue consumers + replay |
| `VISITORS` | `visitor.>` (identified, event.recorded, session.*) | 3 days (high volume → ClickHouse) |
| `KNOWLEDGE` | `knowledge.>` (source.updated, ingestion.started/completed/failed) | 14 days |
| `WORKFLOWS` | `workflow.>` (started, completed, failed, tool.approval.requested, tool.execution.completed) | 14 days |
| `PLATFORM` | `org.>`, `subscription.>`, `usage.>`, `integration.>` | 30 days |
| `ANALYTICS` | `analytics.>` (fan-in subject for ClickHouse consumer) | 24 h after ack |

Subject grammar: `{domain}.{entity}.{action}` (e.g., `conv.message.accepted`). Organization ID travels in the envelope and headers — **not** in subjects (avoids per-tenant subject explosion; per-tenant filtering happens in consumers and ClickHouse).

**Envelope:** CloudEvents 1.0-compatible JSON with mandatory fields: `id` (UUIDv7), `type`, `specversion`, `dataschema` (JSON Schema ref + semver), `source` (service), `time`, and extensions `orgid`, `actorid` (when applicable), `correlationid`, `causationid`. Payload contracts live in `contracts/events/` as JSON Schema, versioned semantically: additive changes bump minor; breaking changes create a new `type` version (`conv.message.accepted.v2`) with a documented deprecation window. CI validates every published event against its schema.

**Reliability (mandatory across all services):**
- **Transactional outbox** for every database-backed publish: event row committed with the business row; a per-service relay publishes to JetStream and marks rows sent. No direct dual writes, ever.
- **Consumer inbox:** durable consumers with explicit ack; each consumer records processed event IDs (`inbox` table keyed by consumer+event ID) and skips duplicates — **all consumers idempotent**; at-least-once is the contract, exactly-once is never assumed.
- JetStream **publish dedup** (`Nats-Msg-Id` = event ID) within the dedup window as a second layer.
- **Retry & DLQ:** bounded redeliveries with backoff (`nak` with delay); on max deliveries, events land in `DLQ.{stream}` with alerting; runbook-driven redelivery after fix. Replay = new durable consumer from a start sequence/time.
- **Backpressure:** pull consumers with bounded batch sizes; consumer lag is a first-class metric (ADR-008).
- **Ordering:** JetStream guarantees per-subject order, but consumers must not rely on cross-subject ordering; per-conversation ordering is carried by the sequence number in the payload (ADR-002), and consumers order by it, not by delivery time.

**What the backbone is not:** not a message store of record (PostgreSQL is), not a task queue for long-running work (Temporal is, ADR-004), not a streaming analytics engine (ClickHouse queries are, ADR-006). Laravel-internal jobs (emails, cache warms) stay on Horizon queues — the backbone is for **cross-service facts**, not intra-service tasks.

## Alternatives considered

1. **Kafka.** Richest ecosystem, strongest ordering/partitioning; heavy operational burden (brokers, partitions, rebalancing) unjustified at initial scale. Explicitly deferred by §32 until throughput or data-platform needs prove it.
2. **RabbitMQ.** Mature, great routing; weaker replay/stream semantics (Streams plugin narrows this) and no built-in per-message dedup; JetStream's persistence + replay + simplicity fits better.
3. **Redis Streams.** Already have Redis; but it's our ephemeral store by decree (§12), consumer-group semantics are weaker, and mixing backbone traffic with cache workloads couples failure domains.
4. **PostgreSQL LISTEN/NOTIFY + polling outbox readers.** No new infra; no durable fan-out, no replay, notify payload limits, and it turns the transactional DB into the bus — exactly the coupling the backbone exists to avoid.
5. **Cloud-native buses (SNS/SQS, Pub/Sub).** Managed; vendor lock-in conflicts with white-label/self-host and regional deployment flexibility (§28).

## Advantages

- Single lightweight binary; trivial local dev (one container) and small production footprint with clustering.
- Persistent streams + durable consumers + replay + dedup cover §8's full requirement list natively.
- CloudEvents + JSON Schema contracts make events testable, documented (AsyncAPI), and evolvable.
- Outbox/inbox as platform-wide law eliminates the classic dual-write and duplicate-processing bug classes by construction.

## Disadvantages

- Smaller ecosystem than Kafka (fewer connectors — the ClickHouse consumer is ours to write and operate).
- At-least-once + idempotency discipline is a per-consumer tax; every new consumer needs an inbox.
- JetStream limits (max message size 1 MB by default) require large payloads to pass by reference (object storage pointer), never inline.

## Security implications

- TLS everywhere; per-service NATS accounts/users with subject-level publish/subscribe permissions (gateway cannot publish `subscription.>`; billing cannot subscribe `conv.>` it doesn't need). Fail closed.
- Events carry org context but **no secrets and no full message bodies beyond what the schema declares**; payload schemas are reviewed for data-minimization (ADR-009).
- DLQ contents are tenant data: same encryption-at-rest and access controls as the database.

## Operational implications

- 3-node NATS cluster in production (Raft); one container locally.
- Monitor: consumer lag per durable, redelivery rates, DLQ depth (alert > 0), stream storage, publish error rates (ADR-008).
- Schema registry is the repo: `contracts/events/` + AsyncAPI docs generated in CI; consumers pin schema versions.

## Cost implications

- Near-negligible infrastructure cost (3 small nodes or managed Synadia). The real cost is engineering discipline (outbox relays, inbox tables) — paid once as shared libraries per language (`packages/shared-contracts` + per-language event SDKs).

## Migration path

- Phase 1: streams above, one ClickHouse consumer, outbox relays in control plane and gateway, inbox library for PHP/Python/Elixir.
- Growth: partition high-volume streams by subject sharding; add mirrors for regional streams (§28: analytics events stay in-region).
- If Kafka ever becomes justified (§32 criteria: verified throughput/ecosystem need, documented benchmark), bridge via a NATS→Kafka connector during transition; CloudEvents envelopes are transport-neutral by design.

## Reversal strategy

- Every producer writes to its **outbox**, not to NATS APIs directly; every consumer reads through a thin subscription adapter. Swapping the transport touches relays and adapters only — business code and event contracts are transport-agnostic.
- Events are facts already persisted in PostgreSQL/ClickHouse; the backbone carries them but never solely owns them, so backbone replacement loses no data.
