"""AI service tests: auth fail-closed, fake-provider determinism, validation."""

from __future__ import annotations

import pytest
from fastapi.testclient import TestClient

from app.config import Settings
from app.main import app

TOKEN = "test-internal-token"


@pytest.fixture()
def client() -> TestClient:
    app.state.settings = Settings(token=TOKEN)
    return TestClient(app)


def payload(**overrides: object) -> dict[str, object]:
    base: dict[str, object] = {
        "organization_id": "0198c5a0-1111-7000-8000-000000000001",
        "conversation_id": "0198c5a0-2222-7000-8000-000000000002",
        "chatbot_name": "Acme Bot",
        "system_prompt": "Be helpful.",
        "history": [{"sender_type": "visitor", "body": "Do you ship to Canada?"}],
        "config": {"provider": "fake"},
    }
    base.update(overrides)
    return base


class TestAuth:
    def test_missing_token_is_401(self, client: TestClient) -> None:
        assert client.post("/v1/reply", json=payload()).status_code == 401

    def test_wrong_token_is_401(self, client: TestClient) -> None:
        response = client.post(
            "/v1/reply", json=payload(), headers={"Authorization": "Bearer nope"}
        )
        assert response.status_code == 401

    def test_health_is_public(self, client: TestClient) -> None:
        assert client.get("/health").status_code == 200


class TestReply:
    def _post(self, client: TestClient, body: dict[str, object]) -> object:
        return client.post(
            "/v1/reply", json=body, headers={"Authorization": f"Bearer {TOKEN}"}
        )

    def test_fake_provider_replies_deterministically(self, client: TestClient) -> None:
        first = self._post(client, payload())
        second = self._post(client, payload())

        assert first.status_code == 200
        data = first.json()
        assert "Do you ship to Canada?" in data["body"]
        assert data["provider"] == "fake"
        assert data["output_tokens"] > 0
        assert first.json() == second.json()

    def test_uses_last_visitor_message(self, client: TestClient) -> None:
        response = self._post(
            client,
            payload(
                history=[
                    {"sender_type": "visitor", "body": "First question"},
                    {"sender_type": "agent", "body": "Agent answer"},
                    {"sender_type": "visitor", "body": "Second question"},
                ]
            ),
        )
        assert "Second question" in response.json()["body"]

    def test_empty_history_is_422(self, client: TestClient) -> None:
        assert self._post(client, payload(history=[])).status_code == 422

    def test_unconfigured_vendor_provider_is_422(self, client: TestClient) -> None:
        response = self._post(client, payload(config={"provider": "anthropic"}))
        assert response.status_code == 422

    def test_oversized_body_is_422(self, client: TestClient) -> None:
        response = self._post(
            client,
            payload(history=[{"sender_type": "visitor", "body": "x" * 16001}]),
        )
        assert response.status_code == 422
