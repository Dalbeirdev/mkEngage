"""Gemini adapter tests — request shaping + response parsing via a mocked
httpx transport (no network, no API key needed). Async methods are driven
with asyncio.run since the project has no pytest-asyncio plugin."""

from __future__ import annotations

import asyncio
import json
from collections.abc import Callable

import httpx
import pytest
from fastapi.testclient import TestClient

from app.config import Settings
from app.main import app
from app.providers.base import ProviderError
from app.providers.embeddings import DIMENSIONS, GeminiEmbedder
from app.providers.gemini_provider import GeminiProvider
from app.schemas import HistoryMessage

TOKEN = "test-internal-token"


def mock_client(handler: Callable[[httpx.Request], httpx.Response]) -> httpx.AsyncClient:
    return httpx.AsyncClient(transport=httpx.MockTransport(handler))


class TestGeminiChat:
    def test_builds_request_and_parses_reply(self) -> None:
        captured: dict[str, object] = {}

        def handler(request: httpx.Request) -> httpx.Response:
            captured["url"] = str(request.url)
            captured["key"] = request.headers.get("x-goog-api-key")
            captured["body"] = json.loads(request.content)
            return httpx.Response(
                200,
                json={
                    "candidates": [{"content": {"parts": [{"text": "Yes, we ship to Canada."}]}}],
                    "usageMetadata": {"promptTokenCount": 12, "candidatesTokenCount": 7},
                },
            )

        provider = GeminiProvider("secret-key", client=mock_client(handler))
        completion = asyncio.run(
            provider.complete(
                system_prompt="Be helpful.",
                history=[
                    HistoryMessage(sender_type="visitor", body="Do you ship to Canada?"),
                    HistoryMessage(sender_type="agent", body="Checking…"),
                ],
                model=None,
                max_output_tokens=256,
                timeout_seconds=10.0,
            )
        )

        assert completion.body == "Yes, we ship to Canada."
        assert completion.input_tokens == 12
        assert completion.output_tokens == 7
        assert completion.model == "gemini-2.0-flash"

        # Key travels in the header, never the URL (no secret in logs).
        assert captured["key"] == "secret-key"
        assert "secret-key" not in captured["url"]
        assert ":generateContent" in captured["url"]

        body = captured["body"]
        assert body["system_instruction"]["parts"][0]["text"] == "Be helpful."
        # Roles mapped: visitor -> user, agent -> model.
        assert [c["role"] for c in body["contents"]] == ["user", "model"]

    def test_http_error_normalizes_to_provider_error(self) -> None:
        def handler(_request: httpx.Request) -> httpx.Response:
            return httpx.Response(500, json={"error": "boom"})

        provider = GeminiProvider("k", client=mock_client(handler))
        history = [HistoryMessage(sender_type="visitor", body="hi")]
        with pytest.raises(ProviderError) as exc:
            asyncio.run(provider.complete("s", history, None, 64, 5.0))
        assert exc.value.provider == "gemini"


class TestGeminiEmbeddings:
    def test_batch_embeds_at_target_dimension(self) -> None:
        captured: dict[str, object] = {}

        def handler(request: httpx.Request) -> httpx.Response:
            captured["body"] = json.loads(request.content)
            return httpx.Response(
                200,
                json={
                    "embeddings": [
                        {"values": [0.1] * DIMENSIONS},
                        {"values": [0.2] * DIMENSIONS},
                    ]
                },
            )

        embedder = GeminiEmbedder("k", client=mock_client(handler))
        vectors = asyncio.run(embedder.embed(["hello", "world"], None, 10.0))

        assert len(vectors) == 2
        assert len(vectors[0]) == DIMENSIONS
        # Dimension pinned so vectors fit the pgvector(1536) column.
        assert captured["body"]["requests"][0]["outputDimensionality"] == DIMENSIONS


class TestGeminiWiring:
    def test_reply_with_gemini_but_no_key_is_422(self) -> None:
        app.state.settings = Settings(token=TOKEN)  # no google_api_key
        client = TestClient(app)
        response = client.post(
            "/v1/reply",
            headers={"Authorization": f"Bearer {TOKEN}"},
            json={
                "organization_id": "0198c5a0-1111-7000-8000-000000000001",
                "conversation_id": "0198c5a0-2222-7000-8000-000000000002",
                "chatbot_name": "Bot",
                "system_prompt": "Be helpful.",
                "history": [{"sender_type": "visitor", "body": "hi"}],
                "config": {"provider": "gemini"},
            },
        )
        assert response.status_code == 422
