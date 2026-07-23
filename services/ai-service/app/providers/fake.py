"""Deterministic provider for tests, local development, and demos — no
network, no keys, stable outputs (workflow-style determinism makes the
end-to-end pipeline verifiable without vendor accounts)."""

from __future__ import annotations

from app.providers.base import Completion
from app.schemas import HistoryMessage


class FakeProvider:
    name = "fake"

    async def complete(
        self,
        system_prompt: str,
        history: list[HistoryMessage],
        model: str | None,
        max_output_tokens: int,
        timeout_seconds: float,
    ) -> Completion:
        last_visitor = next(
            (m.body for m in reversed(history) if m.sender_type in ("visitor", "contact")),
            "",
        )

        body = (
            f"Thanks for your message! You said: “{last_visitor[:200]}”. "
            "A teammate will follow up shortly — is there anything else I can help with?"
        )

        return Completion(
            body=body[: max_output_tokens * 4],
            model=model or "fake-1",
            input_tokens=sum(len(m.body) // 4 for m in history),
            output_tokens=len(body) // 4,
        )
