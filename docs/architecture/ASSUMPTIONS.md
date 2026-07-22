# Domain Assumptions (pending product specification)

These ADRs were written from the **Advanced Technology and Architecture Directive** alone; the mkEngage product/functional specification has not yet been provided. The following domain-level assumptions are inferred from terms the directive uses without defining. **Each needs confirmation or correction at the approval gate — corrections change contracts, not the architecture.**

| # | Assumption | Source hint | Impact if wrong |
|---|---|---|---|
| A1 | **mkEngage Flow** is the visual chatbot/workflow builder edited with React Flow (§3): nodes for messages, branching, conditions, approvals, integration actions; executed conversationally by the AI service and durably by Temporal. | §3, §7 | Flow-definition schema in `contracts/` changes; engines unaffected |
| A2 | **mkEngage Insights** is the analytics product querying ClickHouse (§13): dashboards for conversation volume, agent performance, funnels, cart recovery, AI usage. | §13 | Query-layer scope changes |
| A3 | **Omnichannel** initially means: web widget, mobile SDKs, and at least email + WhatsApp/Messenger-class messaging channels; each external channel adapts inbound/outbound through a channel adapter to the same conversation/message model (`channel_id` on every message, §5). | §1, §5 envelope | Channel-adapter list and contracts change; message model holds |
| A4 | **Visitor vs Contact:** a visitor is an anonymous browser identity (cookie/device-scoped, consent-aware); it may be identified into a contact (person record). Both are org-scoped (§9 lists both). | §9, §4 | Identity-merge workflow changes |
| A5 | **Departments** group agents for routing/assignment; conversations are routed to departments then assigned to agents (agent collision indicators, §5). | §2, §5 | Routing/assignment model changes |
| A6 | **Cart recovery / product-interest analytics** implies e-commerce events (product viewed, cart updated, checkout started/abandoned) emitted by Shopify/WooCommerce integrations and the widget events API into the `VISITORS`/`ANALYTICS` streams. | §13, §20 | Event catalog changes |
| A7 | **Campaigns** are outbound/proactive messages (triggered widget messages, possibly email) with attribution tracked in ClickHouse. Campaign authoring lives in the control plane. | §13 "campaign attribution" | New module in control plane; no new service |
| A8 | **White-label** = reseller organizations with child organizations, custom domains, branding, and possibly reseller-billed subscriptions (§2 white-label configuration, §23 custom domains). | §1, §2, §23 | Billing hierarchy + domain routing change |
| A9 | **Billing** is subscription plans with usage components (AI tokens/messages/seats), most likely Stripe-first behind an adapter; usage metering flows from the per-org meters (ADR-008) — provider unspecified in the directive. | §2 | Billing adapter implementation changes |
| A10 | **Human approvals** (refunds, cancellations) are approvals of *AI-proposed or workflow-embedded actions* surfaced to agents/admins in the dashboard and executed via integrations (Shopify refund, etc.) after approval (§7). | §7 | Approval UX/policy scope changes |
| A11 | **Public API** (§1 "Public API" node) is the same REST/OpenAPI surface as the control-plane API, exposed with API-key scopes — not a separate service. | §1 diagram, §15 | Gateway routing changes |
| A12 | Initial **product languages**: dashboard i18n with RTL support from day one (§3), but launch locales are undefined; assumed English-first with the i18n scaffolding in place. | §3 | Translation workload only |

## Toolchain reality (this machine, 2026-07-23)

Node 24, pnpm, Python 3.12, git present. PHP exists only via XAMPP (no Composer); Docker, Elixir/Erlang, Go, and Temporal CLI are **not installed**. Consequence: Phoenix gateway, Temporal, NATS, ClickHouse, and Postgres work can be scaffolded and unit-designed but **not executed locally** until Docker Desktop (minimum) is installed. Per §33, nothing will be claimed production-ready without verified tests, health checks, and deployment configuration.
