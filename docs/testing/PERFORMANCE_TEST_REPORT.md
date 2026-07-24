# mkEngage — Performance Test Report

Build `c911bcb` · 2026-07-24

## Status: NOT EXECUTED — BLOCKED (k6 binary unavailable in this environment)

The assignment requires k6 smoke/average/stress/spike/soak/recovery tests with p50/p90/p95/p99, throughput, and error-rate reporting against Login, Widget config, Conversation creation, Message acceptance, Message history, WebSocket connections, Knowledge search, etc.

**Progress this QA pass:** a **k6 smoke scaffold was authored** at `perf/k6/smoke.js` — it covers the implemented hot paths (session bootstrap → conversation create → message acceptance → history) and encodes the required thresholds (core API p95 < 400 ms, message acceptance p95 < 300 ms, error rate < 1%). It is **runnable once k6 is installed**:
```
BASE_URL=http://127.0.0.1:8000 SITE_KEY=sk_demo_acme_2026 k6 run perf/k6/smoke.js
```
The **k6 binary is not installed** in this environment (`which k6` → not found; not in winget packages), so the script could not be executed here. Fabricating latency percentiles would violate the testing rules, so **no performance numbers are reported.** Average-load/stress/spike/soak/recovery profiles still need authoring.

## What can be stated honestly (non-load, single-request observations)
These are incidental single-request timings from functional probes — **NOT** a performance benchmark, **NOT** under concurrency:

| Operation | Single-request time (informational only) |
|---|---|
| `POST /api/auth/token` | sub-second |
| `POST /api/widget/conversations/{id}/attachments` (13 B) | 0.243 s |
| Control-plane Pest suite (88 tests, PG) | 9.7 s total |

These do not establish p95 thresholds and must not be read as passing the ≤400 ms / ≤300 ms gates.

## Architectural readiness (review only, not a substitute for load testing)
- Message acceptance is a single row-locked increment + insert + outbox row in one transaction (bounded work).
- Fan-out is decoupled via NATS JetStream (backpressure-friendly) rather than synchronous HTTP.
- RLS adds a `SET LOCAL` per transaction (negligible) but PgBouncer transaction-pooling compatibility is designed in.

## Required before a performance sign-off
1. Author k6 scripts for the listed endpoints + WebSocket connection storm.
2. Establish thresholds: core API p95 < 400 ms, message acceptance p95 < 300 ms, error rate < 1%, zero lost/duplicate accepted messages under load.
3. Monitor CPU/memory/DB connections/consumer lag/WS count during runs.
4. Include a recovery test (gateway + NATS restart under load).

**Performance release gate: NOT MET (not executed).**
