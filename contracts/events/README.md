# Event Contracts

JSON Schema payload contracts for the NATS JetStream backbone (ADR-005). Every event on the bus is wrapped in [envelope.schema.json](envelope.schema.json) (CloudEvents 1.0-compatible); `envelope.data` validates against the payload schema referenced by `envelope.dataschema`.

## Versioning

- **Additive** (new optional field) → bump schema semver minor; same event `type`.
- **Breaking** (remove/rename/retype/new required field) → new `type` version (`conv.message.accepted.v2`) with a new schema file; producers dual-publish during a documented deprecation window; consumers migrate before the old type stops.
- CI validates every published event fixture against its schema (`tests/contract`).

## Catalog

| Type | Stream | Producer | Schema |
|---|---|---|---|
| `conv.created.v1` | CONVERSATIONS | control-plane / gateway | [defined](conv.created.schema.json) |
| `conv.message.accepted.v1` | CONVERSATIONS | realtime-gateway | [defined](conv.message.accepted.schema.json) |
| `conv.message.delivered.v1` | CONVERSATIONS | realtime-gateway | Phase 1 |
| `conv.message.read.v1` | CONVERSATIONS | realtime-gateway | Phase 1 |
| `conv.agent.assigned.v1` | CONVERSATIONS | control-plane | [defined](conv.agent.assigned.schema.json) |
| `conv.transferred.v1` | CONVERSATIONS | control-plane | Phase 1 |
| `conv.closed.v1` | CONVERSATIONS | control-plane | Phase 1 |
| `visitor.identified.v1` | VISITORS | control-plane / gateway | [defined](visitor.identified.schema.json) |
| `visitor.event.recorded.v1` | VISITORS | gateway (widget events API) | [defined](visitor.event.recorded.schema.json) |
| `knowledge.source.updated.v1` | KNOWLEDGE | control-plane | Phase 1 |
| `knowledge.ingestion.completed.v1` | KNOWLEDGE | ingestion-worker | [defined](knowledge.ingestion.completed.schema.json) |
| `workflow.started.v1` / `workflow.completed.v1` / `workflow.failed.v1` | WORKFLOWS | workflow-workers | Phase 1 |
| `workflow.tool.approval.requested.v1` | WORKFLOWS | workflow-workers | [defined](workflow.tool.approval.requested.schema.json) |
| `workflow.tool.execution.completed.v1` | WORKFLOWS | workflow-workers | Phase 1 |
| `integration.failed.v1` | PLATFORM | any | Phase 1 |
| `subscription.changed.v1` | PLATFORM | control-plane | [defined](subscription.changed.schema.json) |
| `usage.limit.reached.v1` | PLATFORM | control-plane | Phase 1 |

Stream/subject topology, retention, and consumer bindings: [../asyncapi/nats-events.yaml](../asyncapi/nats-events.yaml).

## Rules

- `orgid` mandatory on every event; consumers scope processing to it (RULES-tenant-isolation).
- Payloads are **data-minimized**: no message bodies, no credentials, no PII beyond IDs; full records are read from PostgreSQL under authorization.
- Payloads > 256 KB pass by object-storage reference.
- All consumers are idempotent (inbox by envelope `id`); at-least-once is the delivery contract.
