# Contracts

Source of truth between all mkEngage services (ADR-001). Code conforms to contracts, not the reverse; breaking a contract fails CI (§26).

| Directory | Contract | Consumers |
|---|---|---|
| [openapi/](openapi/) | REST API — OpenAPI 3.1 ([control-plane.v1.yaml](openapi/control-plane.v1.yaml)) | Dashboard, `packages/api-client`, `integrations/*`, customers |
| [asyncapi/](asyncapi/) | WebSocket protocol ([realtime-gateway.yaml](asyncapi/realtime-gateway.yaml)) and NATS topology ([nats-events.yaml](asyncapi/nats-events.yaml)) | Widget, SDKs, all services |
| [events/](events/) | JSON Schema event payloads + [envelope](events/envelope.schema.json) | All backbone producers/consumers |
| [webhooks/](webhooks/) | Outbound webhook delivery + signing | Customer endpoints, SDK verifiers |

Generation targets (implementation phases): TypeScript types (`packages/shared-contracts`), REST client (`packages/api-client`), PHP SDK models, contract-test fixtures (`tests/contract`).
