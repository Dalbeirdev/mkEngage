"""Provider abstraction (ADR-003): business logic imports THIS, never a
vendor SDK. Adapters are selected per request from ProviderConfig."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Protocol

from app.schemas import HistoryMessage


@dataclass(frozen=True)
class Completion:
    body: str
    model: str
    input_tokens: int
    output_tokens: int


class ModelProvider(Protocol):
    name: str

    async def complete(
        self,
        system_prompt: str,
        history: list[HistoryMessage],
        model: str | None,
        max_output_tokens: int,
        timeout_seconds: float,
    ) -> Completion: ...


class ProviderError(RuntimeError):
    """Normalized provider failure — callers see this, never vendor exceptions."""

    def __init__(self, provider: str, detail: str) -> None:
        self.provider = provider
        self.detail = detail
        super().__init__(f"{provider}: {detail}")
