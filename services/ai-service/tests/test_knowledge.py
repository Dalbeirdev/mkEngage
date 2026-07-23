"""Embed endpoint + context-grounded replies (RAG slice, ADR-003)."""

from __future__ import annotations

import pytest
from fastapi.testclient import TestClient

from app.config import Settings
from app.main import app

TOKEN = "test-internal-token"
AUTH = {"Authorization": f"Bearer {TOKEN}"}


@pytest.fixture()
def client() -> TestClient:
    app.state.settings = Settings(token=TOKEN)
    return TestClient(app)


class TestEmbed:
    def test_requires_auth(self, client: TestClient) -> None:
        assert client.post("/v1/embed", json={"texts": ["x"]}).status_code == 401

    def test_deterministic_normalized_vectors(self, client: TestClient) -> None:
        body = {"texts": ["shipping to canada", "shipping to canada"]}
        first = client.post("/v1/embed", json=body, headers=AUTH).json()
        second = client.post("/v1/embed", json=body, headers=AUTH).json()

        assert first == second
        assert first["dimensions"] == 1536
        assert first["vectors"][0] == first["vectors"][1]
        norm = sum(v * v for v in first["vectors"][0])
        assert abs(norm - 1.0) < 1e-6

    def test_similar_texts_are_closer_than_unrelated(self, client: TestClient) -> None:
        response = client.post(
            "/v1/embed",
            json={
                "texts": [
                    "shipping delivery canada times",
                    "canada shipping delivery estimate",
                    "gardening tulip bulbs spring soil",
                ]
            },
            headers=AUTH,
        ).json()

        a, b, c = response["vectors"]
        dot = lambda x, y: sum(p * q for p, q in zip(x, y, strict=True))  # noqa: E731
        assert dot(a, b) > dot(a, c)

    def test_empty_texts_rejected(self, client: TestClient) -> None:
        assert client.post("/v1/embed", json={"texts": []}, headers=AUTH).status_code == 422

    def test_unconfigured_openai_rejected(self, client: TestClient) -> None:
        response = client.post(
            "/v1/embed",
            json={"texts": ["x"], "config": {"provider": "openai"}},
            headers=AUTH,
        )
        assert response.status_code == 422


class TestGroundedReply:
    def test_reply_uses_context_chunks(self, client: TestClient) -> None:
        response = client.post(
            "/v1/reply",
            json={
                "organization_id": "o",
                "conversation_id": "c",
                "history": [{"sender_type": "visitor", "body": "How long is shipping?"}],
                "context_chunks": [
                    {
                        "content": "Standard shipping to Canada takes 5-7 business days.",
                        "document_title": "Shipping policy",
                    }
                ],
                "config": {"provider": "fake"},
            },
            headers=AUTH,
        )

        assert response.status_code == 200
        assert "5-7 business days" in response.json()["body"]
        assert "Based on our documentation" in response.json()["body"]

    def test_reply_without_context_keeps_old_behavior(self, client: TestClient) -> None:
        response = client.post(
            "/v1/reply",
            json={
                "organization_id": "o",
                "conversation_id": "c",
                "history": [{"sender_type": "visitor", "body": "Hello"}],
                "config": {"provider": "fake"},
            },
            headers=AUTH,
        )

        assert "Thanks for your message" in response.json()["body"]
