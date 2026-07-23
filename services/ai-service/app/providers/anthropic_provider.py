"""Anthropic adapter (official SDK, ADR-003). History maps to the Messages
API; consecutive same-role messages are merged (API requirement)."""

from __future__ import annotations

import anthropic

from app.providers.base import Completion, ProviderError
from app.schemas import HistoryMessage

_DEFAULT_MODEL = "claude-haiku-4-5-20251001"


class AnthropicProvider:
    name = "anthropic"

    def __init__(self, api_key: str) -> None:
        self._api_key = api_key

    async def complete(
        self,
        system_prompt: str,
        history: list[HistoryMessage],
        model: str | None,
        max_output_tokens: int,
        timeout_seconds: float,
    ) -> Completion:
        messages: list[dict[str, str]] = []
        for item in history:
            role = "user" if item.sender_type in ("visitor", "contact") else "assistant"
            if messages and messages[-1]["role"] == role:
                messages[-1]["content"] += "\n" + item.body
            else:
                messages.append({"role": role, "content": item.body})

        if messages and messages[0]["role"] == "assistant":
            messages.insert(0, {"role": "user", "content": "(conversation opened)"})

        client = anthropic.AsyncAnthropic(api_key=self._api_key, timeout=timeout_seconds)
        try:
            response = await client.messages.create(
                model=model or _DEFAULT_MODEL,
                max_tokens=max_output_tokens,
                system=system_prompt,
                messages=messages,  # type: ignore[arg-type]
            )
        except anthropic.AnthropicError as error:  # normalize (ADR-003)
            raise ProviderError(self.name, str(error)) from error

        body = "".join(
            block.text for block in response.content if block.type == "text"
        )

        return Completion(
            body=body,
            model=response.model,
            input_tokens=response.usage.input_tokens,
            output_tokens=response.usage.output_tokens,
        )
