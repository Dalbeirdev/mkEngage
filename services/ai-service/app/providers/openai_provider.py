"""OpenAI adapter (official SDK, ADR-003)."""

from __future__ import annotations

import openai

from app.providers.base import Completion, ProviderError
from app.schemas import HistoryMessage

_DEFAULT_MODEL = "gpt-4o-mini"


class OpenAIProvider:
    name = "openai"

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
        messages: list[dict[str, str]] = [{"role": "system", "content": system_prompt}]
        for item in history:
            role = "user" if item.sender_type in ("visitor", "contact") else "assistant"
            messages.append({"role": role, "content": item.body})

        client = openai.AsyncOpenAI(api_key=self._api_key, timeout=timeout_seconds)
        try:
            response = await client.chat.completions.create(
                model=model or _DEFAULT_MODEL,
                max_completion_tokens=max_output_tokens,
                messages=messages,  # type: ignore[arg-type]
            )
        except openai.OpenAIError as error:  # normalize (ADR-003)
            raise ProviderError(self.name, str(error)) from error

        usage = response.usage

        return Completion(
            body=response.choices[0].message.content or "",
            model=response.model,
            input_tokens=usage.prompt_tokens if usage else 0,
            output_tokens=usage.completion_tokens if usage else 0,
        )
