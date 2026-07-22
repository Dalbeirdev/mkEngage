# ADR-003: AI and RAG Service

- **Status:** Accepted (2026-07-23)
- **Date:** 2026-07-23
- **Governing directive:** §6, §10, §19

## Context

AI is mkEngage's core differentiator: chatbot answers grounded in tenant knowledge (RAG with citations), intent/sentiment detection, summarization, suggested replies, translation, knowledge-gap detection, and **agentic tool execution** (refunds, order lookups, appointments) gated by approval workflows. Constraints:

- Must support OpenAI, Azure OpenAI, Anthropic, Google AI, OpenAI-compatible private endpoints, and customer-provided credentials — with per-tenant primary/fallback routing, budgets, and regional endpoints (§6).
- AI execution must never run inside synchronous Laravel requests (§2) and LangGraph must not become the business workflow engine (§6/§7).
- Knowledge ingestion processes untrusted documents and websites (§19) — a materially different security posture from inference.

## Decision

**Python 3.12+ / FastAPI / Pydantic v2 / Uvicorn**, fully async with structured concurrency (TaskGroups), typed with Pyright-strict, linted with Ruff, OpenTelemetry-instrumented.

**Two deployables, one codebase family:**
- `services/ai-service` — online inference API: RAG answering, classification, summarization, suggested replies, translation, agent (LangGraph) execution.
- `services/ingestion-worker` — offline pipeline: crawling, extraction (PDF/DOCX/spreadsheet/HTML), OCR, classification, chunking, embedding, PII and prompt-injection scanning. Runs in **isolated containers** with no egress except an allow-listed fetch proxy; SSRF/DNS-rebinding/decompression-bomb/infinite-crawl protections per §19. Jobs are resumable (checkpointed per document) and driven by Temporal schedules (ADR-004).

**Provider abstraction:** a `ModelProvider` protocol (chat, embed, rerank, stream, count_tokens) with per-provider adapters. Business logic imports the abstraction only; adapters are selected by per-tenant `ProviderConfig` (primary/fallback provider+model, embedding + rerank models, timeouts, retry limits, monthly and per-conversation budgets, max input/output sizes, data-retention preference, regional endpoint, allowed tools). Fallback triggers on provider 5xx/timeout/rate-limit with circuit breaking; token usage and cost are metered per request into the analytics stream (ADR-005) and enforced against budgets **before** dispatch (fail closed when budget exhausted, with a tenant-visible reason).

**RAG pipeline:** hybrid retrieval in PostgreSQL — pgvector HNSW semantic similarity + Postgres full-text — fused with Reciprocal Rank Fusion, filtered by tenant, permission scope, document status, and freshness, then optionally reranked (§10). Every answer carries chunk-level citations and a confidence score; low confidence routes to knowledge-gap logging and human handoff. A `VectorStore` adapter isolates pgvector so a dedicated vector platform can be swapped in later without touching business logic.

**Agentic execution:** LangGraph holds **per-conversation agent state and tool selection only**. Tools are declared with JSON Schema, risk levels, and required permissions. Low-risk tools execute inline; any tool marked approval-required or long-running is **handed to Temporal** (ADR-004) and the graph suspends on the pending outcome. The AI service is stateless across requests except for checkpointed graph state (Postgres-backed checkpointer, org-scoped).

**Invocation model:** the gateway/control plane request AI work via authenticated internal HTTP for streaming responses (tokens streamed back through the gateway to the client) and via NATS for non-interactive jobs (summarize-on-close, gap analysis). Prompt templates are versioned artifacts (`prompts/` with semantic versions) and every response records template version + model + parameters for evaluation and audit.

**AI safety enforcement:** input/output guardrails (prompt-injection heuristics on retrieved chunks, output filtering, tool-argument validation against schema) run in-service; retrieved knowledge is treated as untrusted data, never as instructions.

## Alternatives considered

1. **AI inside Laravel (queued jobs + HTTP SDKs).** Fewer services; PHP's async/streaming and AI ecosystem lag badly, and heavy inference in Horizon workers starves product queues. Forbidden by §2.
2. **LangChain (classic) instead of LangGraph.** Larger ecosystem; weaker explicit state/checkpoint semantics for multi-step agents. LangGraph chosen per directive, but confined to agent-state orchestration.
3. **LangGraph as the workflow engine too.** One orchestrator; fails durability/approval requirements (weeks-long timers, compensation, versioned replay) that Temporal is built for. Forbidden by §6.
4. **Direct provider SDK calls without abstraction.** Faster to ship; locks business logic to vendors and breaks per-tenant routing/fallback/budget requirements. Forbidden by §6.
5. **Node.js AI service.** Shares dashboard language; Python's AI tooling (extraction, OCR, evaluation) is decisive for the ingestion side, and one language for both AI deployables wins.

## Advantages

- Per-tenant provider choice, fallback, budgets, and residency (§6, §28) are first-class, enabling enterprise and white-label sales.
- Stateless inference scales horizontally; ingestion isolation contains the riskiest input path.
- Adapter seams (provider, vector store) keep the fastest-moving vendor landscape swappable.
- Citations + confidence + evaluation hooks make answer quality measurable (Pytest + AI evaluation tests in CI).

## Disadvantages

- Streaming path spans three services (AI → gateway → client); needs careful backpressure and cancellation propagation.
- LangGraph checkpoint schema becomes state we must migrate across versions.
- Provider abstraction is a lowest-common-denominator; provider-specific features (e.g., native tool-use variants) need capability flags.

## Security implications

- Customer provider credentials are envelope-encrypted at rest (ADR-009), decrypted only in-memory per request, never logged.
- Tenant isolation enforced at retrieval (RLS + explicit org filter — defense in depth, ADR-007); cross-tenant retrieval is a tested failure case.
- Prompt-injection defenses: retrieved chunks sandboxed as data, tool allow-lists per tenant, argument schema validation, approval gates for risk-bearing tools.
- Ingestion containers: read-only FS, no service credentials beyond scoped object-storage and queue access, egress via filtering proxy only.

## Operational implications

- Key metrics: tokens/cost per org, provider latency/error rates, fallback activations, retrieval latency, rerank latency, evaluation scores, ingestion throughput and failure rates (ADR-008).
- Model/provider outages are business-visible: dashboards + alerts on fallback activation.
- Prompt changes ship like code: versioned, reviewed, evaluated against golden sets before rollout.

## Cost implications

- Dominant platform cost. Controls: per-tenant budgets (hard), per-conversation caps, model routing (cheap models for classification, premium for answering), embedding cache by content checksum, batch embedding.
- Rerank models optional per tenant/plan — a pricing lever.

## Migration path

- Phase 1: OpenAI + Anthropic adapters, pgvector retrieval, no reranker, inline low-risk tools only.
- Later: Azure/Google/private-endpoint adapters, reranking, evaluation harness expansion, dedicated vector platform **only if** benchmarks show pgvector failing (§10), OpenSearch **only if** §11 criteria are met and documented.

## Reversal strategy

- Provider adapters and the vector-store adapter are the reversal mechanism: any vendor can be dropped without business-logic change.
- If LangGraph is abandoned, the graph definitions are thin: state schema + node functions survive; only the orchestration wiring is rewritten against another state machine.
- If FastAPI/Python were ever replaced, the OpenAPI contract for the AI service (in `contracts/openapi/`) defines the replacement's obligations.
