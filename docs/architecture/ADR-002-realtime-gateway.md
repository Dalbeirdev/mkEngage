# ADR-002: Real-Time Messaging Gateway

- **Status:** Accepted (2026-07-23)
- **Date:** 2026-07-23
- **Governing directive:** §5, §27

## Context

The gateway carries every live conversation: visitor and agent WebSockets, presence, typing, delivery/read receipts, reconnect replay, supervisor monitoring, and live visitor updates. Requirements that shape the design:

- Very high concurrent connection counts with low per-connection overhead.
- **WebSockets are not the system of record** (§5): a client-visible delivery confirmation must not be issued until the message is durably persisted (§27).
- Per-conversation monotonically increasing sequence numbers; dedup, out-of-order handling, reconnect replay.
- Every socket authorized with short-lived signed tokens carrying organization context, revocation checks, and allowed-origin validation.

The critical tension: the system of record is PostgreSQL (owned collectively, ADR-006), but the message hot path terminates in Elixir. Routing every inbound message through Laravel via HTTP before confirming would put a second service, a second network hop, and PHP request overhead on the latency-critical path.

## Decision

**Elixir + Phoenix** (latest stable), using Phoenix Channels, Presence, and PubSub, clustered via the deployment platform (libcluster on Kubernetes).

**Message ingest hot path (accept → persist → confirm) stays inside the gateway:**

1. Client sends message over its authorized channel with a client-generated idempotency key.
2. Gateway validates (size, rate, content type), assigns the per-conversation **sequence number atomically in PostgreSQL** (`UPDATE conversation_sequences ... RETURNING`), and in the **same transaction** inserts the message row and its **transactional-outbox** event row — via Ecto, under transaction-scoped tenant context (`SET LOCAL app.current_org_id`, ADR-007).
3. On commit, the gateway acks the sender (`persisted`), fans out to conversation subscribers via PubSub, and the outbox relay publishes `conversation.message.accepted` to NATS JetStream (ADR-005).
4. Duplicate idempotency keys within the dedup window return the original ack (upsert on `(conversation_id, idempotency_key)` unique index).

Latency budget: **p95 accept→confirm ≤ 150 ms intra-region** (DB write dominated); fan-out to online participants p95 ≤ 250 ms.

**Ownership boundary:** the gateway owns writes to `messages`, `conversation_sequences`, receipts, and its outbox table only — via a dedicated DB role limited to those tables. All other conversation mutations (assignment, transfer, close) go through the control plane and reach the gateway as NATS events.

**Socket authorization:** short-lived (≤ 5 min) signed tokens (Ed25519, JWKS published by the control plane) carrying org ID, principal type/ID, allowed conversations/scopes; verified locally in the gateway (no per-connection HTTP call), with a Redis-backed revocation list checked on join and periodically. Origin allow-list per organization enforced at upgrade time.

**Message envelope** (every message): globally unique ID (UUIDv7), organization ID, conversation ID, channel ID, sender type + ID, sequence number, timestamp, idempotency key, correlation ID.

**Reconnect replay:** client presents last-seen sequence per conversation; gateway replays from PostgreSQL (bounded window) then resumes live. Slow clients get per-socket send-queue caps with drop-and-resync (client refetches via REST) rather than unbounded buffering. Rate limits per socket, per visitor, per org (Redis counters).

Lifecycle states (§27): `created → accepted → persisted → published → delivered → read`, with `failed / redacted / deleted` as terminal/administrative states. `delivered`/`read` receipts are batched writes.

## Alternatives considered

1. **Gateway → Laravel HTTP persist → confirm.** Keeps all writes in one codebase; adds a second service to the hot path (latency, availability coupling: control-plane deploys would drop message throughput). Rejected for the ingest path; retained for administrative mutations.
2. **Gateway → NATS publish → async persister → confirm via event.** Decouples nicely, but "persist before confirm" now spans three hops and two services; confirmation latency and failure modes worsen. JetStream ack ≠ Postgres durability without extra machinery. Rejected.
3. **Laravel Reverb / Soketi as the WebSocket tier.** One less language; PHP/Node connection economics and lack of BEAM-style per-connection supervision, Presence CRDTs, and hot code paths make it unfit at target scale. Forbidden by §2.
4. **Managed WebSockets (Ably/Pusher).** Least ops; per-message pricing at chat volumes is punitive, white-label/regional constraints, and the durable-persist-before-confirm contract still needs our own ingest service. Kept as a documented fallback (see Reversal).

## Advantages

- BEAM: millions of cheap processes, per-connection isolation, supervision trees, built-in distributed PubSub/Presence.
- Single-service, single-transaction hot path satisfies §27 with the fewest failure modes.
- Local token verification keeps join latency flat under load.
- Outbox-in-same-transaction eliminates dual-write loss between DB and event bus.

## Disadvantages

- Second backend language; smaller hiring pool.
- Gateway writes directly to PostgreSQL, so schema changes to `messages` need gateway+control-plane coordination (mitigated: gateway-owned tables are versioned in `contracts/` and migrated by one owner).
- Sequence assignment in Postgres serializes writes per conversation (acceptable: single human conversations are low-rate; hot conversations are bounded by UX reality).

## Security implications

- Tokens are short-lived, audience-scoped, origin-checked, and revocable (Redis deny-list keyed by token ID and by principal for mass revocation on logout/role change).
- Channel joins re-check conversation access server-side; unauthorized subscription attempts are logged as security events.
- Gateway DB role cannot read other tenants' rows (RLS, ADR-007) nor touch non-owned tables.
- Payload size caps, per-org rate limits, and backpressure protect against abusive clients; no message bodies in telemetry (ADR-008).

## Operational implications

- Deployed as a clustered StatefulSet/Deployment with headless service discovery; rolling deploys drain sockets gracefully (clients auto-reconnect with replay, so deploys are user-invisible).
- Key metrics: socket count, join rate, ack latency, replay volume, PubSub lag, outbox relay lag, revocation-list hit rate.
- Load tests (ExUnit + k6 WebSocket scenarios) are part of CI for the channel protocol.

## Cost implications

- BEAM handles ~50–100k idle connections per modest node; connection cost is the cheapest part of the platform. Postgres write throughput on the ingest path is the scaling cost to watch — partitioning and receipt batching (ADR-006) address it.

## Migration path

- Phase 1: single-region cluster, Postgres-backed replay, Redis presence metadata.
- Scale-up: partition `messages` (ADR-006); move replay window to a hot store only if benchmarks demand; regional gateway pods per data region (§28) with region-pinned DB writes.

## Reversal strategy

- Clients speak a **documented channel protocol** (AsyncAPI in `contracts/asyncapi/`), not Phoenix internals. Replacing the gateway (managed provider or another runtime) means re-implementing that contract + the ingest transaction; widget/dashboard/mobile SDKs are unaffected.
- If direct-DB writes prove problematic, fallback is Alternative 1 (HTTP persist via control plane) behind the same client contract — a gateway-internal change.
