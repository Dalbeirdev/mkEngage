# ADR-009: Security Boundaries

- **Status:** Accepted (2026-07-23)
- **Date:** 2026-07-23
- **Governing directive:** §4, §5, §14, §15, §16, §17, §18, §19, §22, §23, §26

## Context

mkEngage's attack surface is unusually broad: a widget embedded on arbitrary third-party websites; public REST APIs with customer API keys; long-lived WebSockets; an AI layer that ingests untrusted documents and web content and can execute real-world actions (refunds, cancellations); stored credentials for customers' Shopify/CRM/SMTP systems; and enterprise SSO. Each is a distinct trust boundary with its own failure modes. The directive mandates fail-closed authorization, standards-based identity, KMS-backed secrets, edge protection, and hard ingestion sandboxing.

## Decision

Define and enforce these trust boundaries, outermost first:

**1. Edge (§23):** Cloudflare (or equivalent) in front of everything — CDN, WAF, DDoS, bot management, TLS, rate limiting, custom domains for white-label. Explicit cache-control tiers: public widget assets (immutable, long TTL), public widget config (short TTL), authenticated APIs (`no-store`), knowledge content and attachments (private, signed), branding (per-tenant cacheable). **Tenant-sensitive responses never enter shared edge caches.**

**2. Widget on hostile pages (§4/§5):** the widget runs inside customer pages we don't control. It holds **no secrets** — a public site key only; identity is established via **signed visitor identity** (HMAC of visitor ID with a per-org signing secret, computed server-side by the customer's backend — SDK helpers in the PHP/Laravel/WordPress/Shopify integrations) or anonymous visitor tokens with strictly limited scope. Widget→platform traffic is origin-validated against the org's allow-list, rate-limited per visitor/org, and carries short-lived tokens (ADR-002). Shadow DOM isolates styles, not security: nothing in the widget bundle is trusted server-side, ever. IndexedDB stores conversation cache only — no tokens beyond the current short-lived session token, no PII beyond what the visitor typed.

**3. API surface (§15):** REST/OpenAPI 3.1 with OAuth 2.1-pattern authorization; **scoped API keys** (prefix + hash-at-rest, per-key scopes, IP allow-lists optional, revocable, last-used tracking); idempotency keys on mutating endpoints; Problem Details errors that never leak internals; cursor pagination; rate-limit headers. Webhooks **signed** (HMAC-SHA256, timestamped, replay-window enforced) with per-endpoint secrets.

**4. Application authorization (§16):** combined **RBAC + ABAC**, fail closed. Laravel policies/gates are the decision point for the control plane; decisions may consider org, role, permission, department, conversation assignment, plan, region, data classification, tool risk level, approval status. The same policy outcomes are enforced at the gateway (channel authorization) and AI service (tool allow-lists) via signed context claims — services re-verify, never trust upstream assertions alone (ADR-007). **OpenFGA-compatible adapter interface** defined now; OpenFGA deployed only when relationship complexity demands (§16).

**5. Identity (§17):** Fortify + Sanctum for first-party auth; TOTP MFA, recovery codes, WebAuthn-ready session layer, session revocation. Enterprise SSO through a provider-adapter layer: OIDC, OAuth 2.1, SAML 2.0, SCIM 2.0 provisioning — adapters for Entra ID, Okta, Google Workspace, Auth0, WorkOS; **no vendor coupling**. SSO/SCIM configuration is per-organization; JIT provisioning honors SCIM deprovision immediately (token revocation fan-out via Redis deny-list, ADR-002).

**6. Secrets (§18):** cloud KMS or Vault-compatible store; **envelope encryption** for customer integration secrets (AI keys, OAuth refresh tokens, Shopify tokens, CRM/SMTP credentials, webhook secrets) — DEKs per org, wrapped by environment KEKs, rotated on schedule and on incident; decrypt only in-memory at point of use. Plaintext secrets are banned from source control, images, frontend bundles, logs, error reports, and CI files — enforced by Gitleaks (CI-blocking), image scanning (Trivy), and the telemetry allow-list (ADR-008).

**7. AI and ingestion (§6/§19):** ingestion workers run in isolated containers (read-only FS, dropped capabilities, no service mesh credentials, egress only via an allow-listing fetch proxy) with defenses against SSRF/private-network access/redirect abuse/DNS rebinding/oversized files/decompression bombs/malformed documents/infinite crawls; PII detection and prompt-injection scanning happen at ingestion **and** retrieval treats chunks as data, never instructions (ADR-003). Agentic tools: risk-tiered, org-allow-listed, schema-validated arguments, approval-gated via Temporal for consequential actions (ADR-004) — the AI can propose, only policy-checked humans (or explicitly configured auto-approval for low-risk tiers) dispose.

**8. Internal boundary:** services are unreachable except via the API gateway/edge; Kubernetes NetworkPolicies segment east-west traffic; internal HTTP uses short-lived service tokens (mTLS where the platform provides it); NATS accounts restrict pub/sub per service (ADR-005); per-service DB roles (ADR-006/007).

**9. Audit & data protection:** append-only audit log (actor, org, action, subject, IP, timestamp, reason where applicable) for authentication events, permission changes, approvals, exports/deletions, platform-role access, secret access; consent records and retention policies per §29 (ADR-006); security logs to the 90-day tier (ADR-008).

**Security testing (§26):** OWASP ZAP dynamic scans, Semgrep static rules, Trivy container/dependency scans, Gitleaks — all CI-blocking on critical findings; cross-tenant tests per ADR-007; abuse-case tests (webhook replay, token reuse, origin spoofing) in the platform security suite (`tests/security/`).

## Alternatives considered

1. **Central policy engine (OPA) for all services now.** Uniform decisions; another critical-path dependency and policy language before the domain is stable. Deferred; the OpenFGA-shaped adapter covers the future need (§16).
2. **API gateway product (Kong/Tyk) for authN/Z.** Offloads key management; duplicates Laravel policy logic at a second source of truth and complicates white-label routing. Edge + app-layer enforcement chosen instead.
3. **Widget with third-party auth SDKs / silent SSO.** Richer identity; unacceptable secret-exposure surface on hostile pages. Signed-identity HMAC pattern (industry standard for chat widgets) chosen.
4. **Vault everywhere including app config.** Strongest secret story; heavy ops for a small team — KMS-backed envelope encryption + platform secret injection covers §18 with less machinery (Vault-compatible interface retained).
5. **Ingestion in-process with the AI service.** Simpler deploys; mixes the most dangerous input path with the most credential-rich service. Rejected outright.

## Advantages

- Every boundary fails closed and is independently testable; compromise of one layer (widget, edge, even app scoping) does not cascade (RLS backstop, envelope encryption, network segmentation).
- Standards-based identity and signed webhooks make enterprise procurement and integration reviews tractable.
- Secrets design means a database dump alone yields no usable customer credentials.
- CI-enforced scanning turns security regressions into build failures.

## Disadvantages

- Fail-closed + multi-layer verification adds latency and engineering friction at every boundary (accepted deliberately).
- Adapter layers (SSO, FGA, KMS) are upfront cost before any single vendor is used in anger.
- The egress proxy and container sandboxing for ingestion is genuinely fiddly ops work.
- Signed visitor identity requires customer-side implementation — SDK helpers reduce but don't remove integration friction.

## Security implications

This ADR is the security architecture; residual risks called out explicitly: social engineering of org admins (mitigation: MFA default-on prompts, audit visibility), malicious white-label resellers (mitigation: reseller children isolated as full tenants, ADR-007), AI prompt-injection reaching tool execution (mitigation: risk tiers + approvals + argument schemas — treated as *when*, not *if*), supply-chain compromise (mitigation: lockfiles, Trivy, minimal base images, SBOM generation).

## Operational implications

- Key rotation (KEKs, signing keys, webhook secrets) is runbook-scheduled and drill-tested; JWKS rotation is zero-downtime by design (ADR-002).
- Security-relevant alerts (RLS test failures, DLQ security events, scan criticals, anomalous platform-role use) page like availability incidents.
- Quarterly access review of platform roles and break-glass usage; incident-response runbook in `docs/`.

## Cost implications

- Edge (Cloudflare business tier for white-label domains), KMS per-key/per-op micro-costs, scanner CI minutes — modest. The dominant cost is engineering time on adapters and sandboxing; the counterfactual (breach, failed enterprise security review) prices it correctly.

## Migration path

- Phase 1: edge + widget signing + API keys/scopes + policies + Fortify/Sanctum + MFA + envelope encryption + ingestion sandbox + audit log + CI scanners.
- Phase 2+: SSO/SCIM adapters (first enterprise customer), WebAuthn passkeys, OpenFGA when relationship queries appear, mTLS mesh if/when the platform adds one.

## Reversal strategy

- Each boundary is adapter-isolated: edge provider, SSO vendors, KMS backend, FGA engine are all swappable behind interfaces without touching business logic.
- The non-reversible commitments are the *patterns* (fail-closed, envelope encryption, signed identity) — deliberately so; reversing those would be lowering the security posture, and any such change requires a superseding ADR with explicit risk acceptance.
