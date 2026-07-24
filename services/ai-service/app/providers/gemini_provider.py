"""Google Gemini adapter (ADR-003).

Talks to the Gemini REST API over httpx (already a dependency) rather than a
vendor SDK — keeps the dependency surface small and makes the adapter trivially
mockable in tests via an injected client/transport. The API key is read from
config (env/secret injection only, §18) and sent in the x-goog-api-key header,
never in the URL/query string (no secret in logs or history).
"""

from __future__ import annotations

import httpx

from app.providers.base import Completion, ProviderError
from app.schemas import HistoryMessage

_BASE_URL = "https://generativelanguage.googleapis.com/v1beta"
_DEFAULT_MODEL = "gemini-2.0-flash"


class GeminiProvider:
    name = "gemini"

    def __init__(self, api_key: str, client: httpx.AsyncClient | None = None) -> None:
        self._api_key = api_key
        self._client = client  # injected in tests

    async def complete(
        self,
        system_prompt: str,
        history: list[HistoryMessage],
        model: str | None,
        max_output_tokens: int,
        timeout_seconds: float,
    ) -> Completion:
        model_name = model or _DEFAULT_MODEL

        contents = [
            {
                # Gemini roles are user | model.
                "role": "user" if item.sender_type in ("visitor", "contact") else "model",
                "parts": [{"text": item.body}],
            }
            for item in history
        ]

        body = {
            "system_instruction": {"parts": [{"text": system_prompt}]},
            "contents": contents,
            "generationConfig": {"maxOutputTokens": max_output_tokens},
        }

        client = self._client or httpx.AsyncClient(timeout=timeout_seconds)
        owns_client = self._client is None
        try:
            response = await client.post(
                f"{_BASE_URL}/models/{model_name}:generateContent",
                headers={"x-goog-api-key": self._api_key},
                json=body,
            )
            response.raise_for_status()
            data = response.json()
        except (httpx.HTTPError, ValueError) as error:  # normalize (ADR-003)
            raise ProviderError(self.name, str(error)) from error
        finally:
            if owns_client:
                await client.aclose()

        return Completion(
            body=_first_text(data),
            model=model_name,
            input_tokens=_usage(data, "promptTokenCount"),
            output_tokens=_usage(data, "candidatesTokenCount"),
        )


def _first_text(data: dict) -> str:
    candidates = data.get("candidates") or []
    if not candidates:
        return ""
    parts = candidates[0].get("content", {}).get("parts") or []
    return "".join(part.get("text", "") for part in parts)


def _usage(data: dict, key: str) -> int:
    value = data.get("usageMetadata", {}).get(key, 0)
    return value if isinstance(value, int) else 0
