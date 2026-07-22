# mkEngage

Multi-tenant, white-label, omnichannel chatbot and customer-engagement platform.

Authoritative technology spec: **mkEngage Advanced Technology and Architecture Directive** (PDF, repo root). Architecture decisions: [docs/architecture](docs/architecture/README.md).

## Repository map (directive §31)

| Path | Contents | Stack |
|---|---|---|
| `apps/control-plane` | SaaS control plane — auth, tenancy, billing, administration | Laravel + Octane |
| `apps/dashboard` | Admin/agent dashboard | Next.js (App Router, TS strict) |
| `apps/docs-site` | Public documentation site | Next.js |
| `apps/shopify-app` | Shopify embedded app | Shopify App Bridge |
| `services/realtime-gateway` | WebSocket gateway — channels, presence, message ingest | Elixir/Phoenix |
| `services/ai-service` | AI + RAG — routing, retrieval, agent execution | Python/FastAPI + LangGraph |
| `services/ingestion-worker` | Knowledge ingestion — crawl, extract, chunk, embed | Python (sandboxed) |
| `services/workflow-workers` | Temporal workers (`php/`, `python/`) | Temporal SDKs |
| `services/analytics-consumer` | NATS → ClickHouse ingest | Python |
| `packages/chat-widget` | Universal website widget | Lit + Web Components (not React) |
| `packages/shared-contracts` | Generated types from `contracts/` | TS |
| `packages/api-client` | Generated REST client | TS |
| `packages/{react,vue,angular,react-native,flutter}-sdk` | Framework wrappers around the widget/protocol | per framework |
| `integrations/` | PHP SDK, Laravel package, WordPress/WooCommerce plugin, Shopify | per platform |
| `contracts/` | **Source of truth between services** — OpenAPI 3.1, AsyncAPI, JSON Schema events, webhooks | — |
| `infrastructure/` | Docker, Kubernetes, Helm, Terraform, observability | — |
| `tests/` | Cross-service: contract, e2e, performance (k6), security (ZAP) | — |
| `docs/` | ADRs, platform rules, runbooks | — |

## Platform rules (binding)

- [Tenant isolation](docs/architecture/RULES-tenant-isolation.md)
- [Message ordering](docs/architecture/RULES-message-ordering.md)
- [Failure and retry behavior](docs/architecture/RULES-failure-retry.md)
- [Minimum production deployment](docs/architecture/DEPLOYMENT-minimum-production.md)

## Status

Phase 0 (architecture) complete and approved. Contracts defined in `contracts/`. Service implementation phases begin with the control plane.
