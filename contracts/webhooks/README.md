# Outbound Webhooks

Customer-facing webhook delivery contract (§15, ADR-009, RULES-failure-retry).

## Delivery

- HTTPS POST, JSON body = the same CloudEvents envelope as the internal bus ([../events/envelope.schema.json](../events/envelope.schema.json)) with payload per the event catalog — customers subscribe per event type in the dashboard/API.
- Delivered by a Temporal workflow per endpoint: 10 s timeout, exponential backoff retries over ≤ 24 h, then `failed` with tenant-visible status.
- At-least-once: consumers dedupe by `id`. Order is not guaranteed across events; conversation order is recoverable from `data.sequence_number` where present.

## Signing

Headers on every delivery:

| Header | Value |
|---|---|
| `mkengage-webhook-id` | Envelope `id` |
| `mkengage-webhook-timestamp` | Unix seconds at send time |
| `mkengage-webhook-signature` | `v1=HMAC-SHA256(secret, "{id}.{timestamp}.{body}")` hex |

Verification rules (implemented in the PHP SDK / Laravel middleware / WordPress plugin):
1. Reject if `|now - timestamp| > 300 s` (replay window).
2. Compute the HMAC with the endpoint's secret (shown once at creation; rotatable — during rotation both old and new signatures are sent as `v1=...,v1=...` and either may match).
3. Constant-time compare.

## Endpoint management

- Endpoints are org-scoped resources (see OpenAPI: `/v1/webhook-endpoints`) with per-endpoint secrets, subscribed event types, and delivery logs.
- Automatic disable after 7 consecutive days of total failure, with notification before and after.
- Webhook secrets are envelope-encrypted at rest (§18); never retrievable after creation, only rotatable.
