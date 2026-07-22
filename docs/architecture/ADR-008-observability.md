# ADR-008: Observability

- **Status:** Accepted (2026-07-23)
- **Date:** 2026-07-23
- **Governing directive:** §25

## Context

A single user action (visitor sends a message) crosses up to six systems: widget → edge → gateway → PostgreSQL → NATS → AI service → provider → back through the gateway. Debugging that without unified telemetry is guesswork; billing disputes need per-org AI usage truth; SLAs need latency evidence. The directive mandates OpenTelemetry across all services, a specific self-hostable backend set, vendor neutrality, and strict telemetry hygiene (no message bodies, passwords, tokens, payment details, or sensitive knowledge content).

## Decision

**OpenTelemetry (traces, metrics, logs) in every service**, exported via OTLP to a central **OpenTelemetry Collector**, which routes to:

- **Tempo** — distributed traces
- **Prometheus** (+ remote-write-compatible store) — metrics
- **Loki** — structured logs
- **Grafana** — dashboards and alerting UI (Alertmanager for routing)
- **Sentry-compatible error tracking** (Sentry or GlitchTip self-hosted) — exceptions with release + trace links

Instrumentation per stack: Laravel via OTel PHP SDK + auto-instrumentation (HTTP, PDO, Redis, queues) with Octane-safe context; Phoenix via OpenTelemetry Erlang/Elixir (+ Ecto/Phoenix integrations); Python via OTel SDK (FastAPI, HTTPX, asyncpg auto-instrumentation); Next.js via OTel Node for server-side; the widget reports only anonymous operational health (connect failures, reconnect counts) through a first-party endpoint — no third-party telemetry from customer pages.

**Context propagation:** W3C `traceparent` end-to-end — HTTP between services, message headers on NATS (producer span → consumer span links), Temporal interceptors carry trace context into workflows/activities, and the gateway stamps socket sessions so channel events join the trace. **Correlation ID** (request/event) and **causation ID** ride alongside (ADR-005) and appear in every log line via shared logging middleware: `trace_id`, `span_id`, `correlation_id`, `org_id`, `service`, `version`.

**Mandatory signals (§25):** request/queue/workflow/AI/retrieval durations; provider error rates; WebSocket connection counts and churn; event-consumer lag per durable; DB and pooler latency; ClickHouse ingestion lag; outbox relay lag; Temporal task-queue schedule-to-start latency; per-org token/cost meters (also the billing source, double-written to ClickHouse via events — ADR-006).

**Hygiene (hard rules):** span attributes and logs are **allow-listed, not deny-listed** — shared telemetry libraries per language expose typed helpers (`recordAiCall(org, model, tokens, latency)`) and refuse arbitrary string payloads; message bodies, prompts/completions, tokens/credentials, payment data, and knowledge content never enter telemetry. Org IDs appear in **protected internal telemetry only** (Grafana org-scoped folders; no tenant-facing exposure). Collector runs a redaction processor (pattern-based scrubbing) as a second layer. Error-tracker payloads scrub request bodies and headers by default.

**Sampling:** head-based 100% for errors and slow requests (tail-sampling policy in the Collector: keep error traces, keep p99-latency traces, sample 10% of the rest); metrics and logs unsampled. Retention: traces 7d, logs 14d (security-relevant logs 90d to the audit pipeline, ADR-009), metrics 13 months downsampled.

**SLOs (initial):** gateway ack p95 ≤ 150 ms (ADR-002); REST API p95 ≤ 300 ms; AI first-token p95 ≤ 3 s; event consumer lag < 30 s; CH ingestion lag < 60 s; error budget alerts, not per-blip paging.

## Alternatives considered

1. **Datadog/New Relic end-to-end.** Fastest setup, best-in-class UX; per-host/per-GB pricing at chat-message log volumes is punitive, and vendor lock-in contradicts §25's neutrality requirement and white-label self-hosting. OTel keeps this as a *backend swap*, not an instrumentation rewrite — explicitly supported by our design.
2. **ELK (Elasticsearch/Logstash/Kibana).** Powerful log search; JVM operational weight and log-first (not trace-first) model; Loki's label-based indexing is far cheaper at our volumes.
3. **Per-service tooling (Telescope, Phoenix LiveDashboard, Flower).** Great locally, kept for dev — but no cross-service story, which is the actual problem.
4. **Jaeger instead of Tempo.** Mature tracing; Tempo's object-storage backend and native Grafana integration make it cheaper to run in our stack. (Interchangeable via OTLP — low-stakes choice.)

## Advantages

- One trace shows widget→gateway→DB→NATS→AI→provider with per-hop timing; consumer lag and outbox lag make async paths as debuggable as sync ones.
- Vendor-neutral by construction: swapping backends is a Collector config change (§25 requirement).
- Allow-listed attributes make privacy violations a compile-time/library-API problem instead of an audit finding.
- Per-org meters serve three masters at once: billing, abuse detection, capacity planning.

## Disadvantages

- Self-hosted LGTM stack is real infrastructure (~4 components + storage) the team must operate, upgrade, and secure.
- OTel PHP (Octane) and Elixir integrations are less turnkey than Node/Python — expect instrumentation-maturity work in Phase 1.
- Tail-sampling needs Collector tuning; mis-tuning silently drops the traces you wanted.
- Telemetry storage grows with traffic; retention policies must be enforced from day one.

## Security implications

- Telemetry contains org IDs and operational metadata → the observability stack is inside the security boundary: SSO-gated Grafana, network-policy-isolated backends, encrypted at rest, access audited (ADR-009).
- Redaction processor + allow-listed attributes are both required; neither alone is sufficient.
- Traces/logs are discoverable evidence in incidents — the 90-day security-log tier feeds the audit pipeline with integrity controls.
- Widget health endpoint is rate-limited and anonymous by design (no visitor identifiers).

## Operational implications

- Deployed via Helm in the `infrastructure/observability/` stack; Docker Compose ships a mini-LGTM for local parity.
- Dashboards-as-code (Grafana provisioning in repo); every service PR that adds a queue/consumer/workflow must add its lag/duration panels (CI checklist).
- Alert runbooks live beside dashboards; alerts page on SLO burn, not raw thresholds.

## Cost implications

- Self-hosted: ~1 small node's worth of compute + object storage for traces/logs — hundreds of GB/month at scale but cheap storage classes; a fraction of SaaS APM pricing at equivalent volume. Managed Grafana Cloud is a viable swap if ops time becomes the constraint (OTLP makes it a config change).

## Migration path

- Phase 1: Collector + LGTM + Sentry-compatible tracker, auto-instrumentation everywhere, the mandatory signal set, initial SLO dashboards.
- Growth: tail-sampling refinement, per-region observability stacks with global federation (§28), continuous profiling (Pyroscope) if CPU mysteries warrant it.

## Reversal strategy

- Instrumentation is OTel — the sunk cost that never needs reversing (§25's point). Any backend (Datadog, Honeycomb, Grafana Cloud, vendor-of-the-decade) accepts OTLP; reversal of the *backends* is a Collector exporter change plus dashboard migration, with zero application changes.
