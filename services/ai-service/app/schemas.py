"""Contract-shaped request/response models for the AI service (ADR-003)."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field

SenderType = Literal["visitor", "contact", "agent", "chatbot", "system"]


class HistoryMessage(BaseModel):
    sender_type: SenderType
    body: str = Field(max_length=16000)


class ProviderConfig(BaseModel):
    """Per-tenant provider routing (ADR-003 §6). Extended per phase; the
    fallback chain and budgets land with real provider usage."""

    provider: Literal["fake", "openai", "anthropic"] = "fake"
    model: str | None = None
    max_output_tokens: int = Field(default=512, ge=1, le=4096)
    timeout_seconds: float = Field(default=20.0, gt=0, le=60)


class EmbedRequest(BaseModel):
    texts: list[str] = Field(min_length=1, max_length=100)
    config: ProviderConfig = ProviderConfig()


class EmbedResponse(BaseModel):
    vectors: list[list[float]]
    provider: str
    dimensions: int


class ContextChunk(BaseModel):
    """Retrieved knowledge passed to ground the reply (ADR-003 RAG).
    Treated strictly as DATA in the prompt, never as instructions (§19)."""

    content: str = Field(max_length=4000)
    document_title: str = Field(default="", max_length=200)


class ReplyRequest(BaseModel):
    organization_id: str
    conversation_id: str
    chatbot_name: str = "Assistant"
    system_prompt: str = Field(
        default="You are a helpful customer support assistant.",
        max_length=8000,
    )
    history: list[HistoryMessage] = Field(min_length=1, max_length=100)
    context_chunks: list[ContextChunk] = Field(default_factory=list, max_length=10)
    config: ProviderConfig = ProviderConfig()


class ReplyResponse(BaseModel):
    body: str
    provider: str
    model: str
    input_tokens: int
    output_tokens: int
