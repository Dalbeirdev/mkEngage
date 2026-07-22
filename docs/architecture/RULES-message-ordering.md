# Platform Rules: Message Ordering and Delivery

Binding rules for conversation messaging. Derived from ADR-002 (gateway) and ADR-005 (backbone). Directive §5, §8, §27.

## Ordering model

1. **Per-conversation total order** is defined by the `sequence_number` — a monotonically increasing integer assigned **atomically in PostgreSQL** at persist time (`UPDATE conversation_sequences SET last_seq = last_seq + 1 ... RETURNING`). Nothing else defines order: not timestamps, not delivery order, not event order.
2. **Timestamps are informational.** Clients display them; no component sorts by them.
3. **Cross-conversation ordering does not exist** and must not be relied upon anywhere.
4. **Events on NATS are ordered per subject** but consumers must tolerate cross-subject interleaving; consumers that need conversation order sort by `sequence_number` from the payload.

## Message identity and lifecycle

5. Every message carries: message ID (UUIDv7), organization ID, conversation ID, channel ID, sender type + sender ID, sequence number, timestamp, idempotency key, correlation ID (§5).
6. Lifecycle: `created → accepted → persisted → published → delivered → read`, with `failed`, `redacted`, `deleted` as administrative/terminal states (§27).
7. **No client-visible delivery confirmation before durable persistence.** The gateway acks a sender only after the Postgres transaction (message + sequence + outbox) commits. WebSocket delivery is never the system of record.

## Deduplication

8. **Client → gateway:** the client generates the idempotency key (UUID per send attempt, reused across retries of the same logical message). The gateway upserts on `(conversation_id, idempotency_key)`; duplicates return the original ack (same message ID and sequence) — never a second row.
9. **Gateway → subscribers:** clients dedupe by message ID; SDKs and the widget must treat re-fan-out after reconnect as expected.
10. **Event consumers:** inbox-dedupe by event ID (ADR-005). At-least-once is the platform contract; **every consumer is idempotent, no exceptions.**

## Reconnect and replay

11. Clients track `last_seen_seq` per conversation. On reconnect they present it; the gateway replays persisted messages `> last_seen_seq` (bounded window), then switches to live fan-out. Gaps beyond the window are filled via REST (cursor-paginated by sequence).
12. **Out-of-order receipt on the client** (live message arrives during replay) is resolved by sequence: render order is always sequence order; duplicates are dropped by message ID.
13. **Slow clients:** per-socket send queues are capped; on overflow the gateway drops the queue and instructs resync (replay-from-sequence) rather than buffering unboundedly.

## Receipts

14. `delivered` and `read` receipts reference (conversation ID, up-to-sequence) — cumulative, not per-message — and are batched server-side. Receipt loss is recoverable: receipts are monotonic high-water marks, so re-sending the latest mark is always safe.

## Editing, redaction, deletion

15. Mutations of an existing message never change its sequence number; they are new facts (`message.redacted` etc.) referencing the original message ID, applied by consumers and clients as overlays.
