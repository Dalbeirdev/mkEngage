# Platform Rules: Failure and Retry Behavior

Binding rules for how every component fails and recovers. Derived from ADR-004, ADR-005; directive §7, §8, §27.

## Universal principles

1. **Fail closed.** Authorization, tenant context, and budget checks that cannot complete deny the action (ADR-009).
2. **At-least-once everywhere.** No component may assume exactly-once delivery of events, jobs, webhooks, or activities. Idempotency is a precondition for shipping, enforced in review and by the shared inbox/idempotency libraries.
3. **Every retry is bounded and backed off.** Unbounded or immediate-tight-loop retries are forbidden. Defaults: exponential backoff with jitter, base 1 s, factor 2, cap 5 min unless a contract specifies otherwise.
4. **Every failure is observable.** Failed operations emit metrics and land somewhere inspectable (DLQ, failed-workflow query, error tracker) — never silently dropped.
5. **Correlation and causation IDs propagate** through every retry, fan-out, and compensation so a failure can be traced to its origin (ADR-008).

## Per-component contracts

### Synchronous HTTP (REST APIs, internal calls)
- Timeouts are mandatory on every outbound call (connect + total); no infinite waits.
- Retries only for idempotent requests (GET, or mutations carrying idempotency keys), max 3 attempts, honoring `Retry-After`.
- Circuit breakers on provider/integration clients: open after sustained failures, half-open probes, per-tenant where budgets apply (ADR-003).
- Mutating endpoints accept an `Idempotency-Key` header; replays within the retention window return the original result (§15).

### NATS JetStream consumers
- Pull consumers, explicit ack after successful processing; `nak` with delay on transient failure.
- Max deliveries bounded (default 5); exhaustion routes to `DLQ.{stream}` with an alert; DLQ redelivery is a runbook action after fix, never automatic.
- Inbox dedupe by event ID before side effects (ADR-005).
- Poison-message rule: a consumer must classify permanent vs transient failures; permanent failures go to DLQ on first detection, not after burning retries.

### Temporal workflows and activities
- Workflow code deterministic; all fallible I/O in activities (ADR-004).
- Every activity declares retry policy (initial interval, backoff, max attempts/duration) and timeouts (schedule-to-close, start-to-close); long activities heartbeat.
- Business failures (refund rejected) are workflow results, not retried errors; infrastructure failures retry per policy.
- Multi-step external mutations define compensation activities; sagas complete or compensate — never half-committed.
- Human approvals expire via timers with escalation, then fail closed (auto-reject with notification).

### Laravel queues (intra-service jobs only)
- Max attempts + backoff configured per job; failed jobs go to the failed-jobs table with alerting; jobs are idempotent (may run twice).
- Anything needing durability across restarts, signals, or > minutes runtime belongs to Temporal, not queues (§7).

### Webhook delivery (outbound to customers)
- Delivered via Temporal workflow: signed payload, timeout 10 s, retries with exponential backoff over ≤ 24 h, then marked failed with tenant-visible status and DLQ record.
- Receiving endpoints are informed of the replay window; deliveries carry event ID for consumer-side dedupe.

### Real-time gateway
- Persist failure ⇒ negative ack to sender (client retries with same idempotency key); no optimistic confirmation (§27).
- Reconnect storms are absorbed by token-validated joins + replay (RULES-message-ordering) and per-org rate limits; backpressure sheds slow clients via bounded queues, never by blocking the fan-out path.
- Outbox relay lag is monitored; relay crash-restarts resume from unsent rows — publishes may duplicate, consumers dedupe (rule 2).

### AI provider calls
- Timeout + bounded retry per provider config; fallback provider/model on 5xx/timeout/rate-limit (ADR-003); budget exhaustion fails closed with a tenant-visible reason, never a silent downgrade.
- Streaming failures mid-response surface as explicit stream-error frames; the client renders partial + error, never a fake completion.

## Recovery drills

Restore-from-backup, DLQ drain, region failover tabletop, and replay-from-stream procedures are runbook items exercised quarterly (ADR-010).
