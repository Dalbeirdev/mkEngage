"""Embedding providers (ADR-003 embedding routing).

FakeEmbedder: deterministic 1536-dim vectors from token hashes — no network,
stable across runs, and crucially SIMILAR TEXTS GET SIMILAR VECTORS (bag of
hashed words), so retrieval ordering is meaningful in tests and demos.
"""

from __future__ import annotations

import hashlib
import math
from typing import Protocol

import httpx

from app.providers.base import ProviderError

DIMENSIONS = 1536


class Embedder(Protocol):
    name: str

    async def embed(
        self, texts: list[str], model: str | None, timeout_seconds: float
    ) -> list[list[float]]: ...


class FakeEmbedder:
    name = "fake"

    async def embed(
        self, texts: list[str], model: str | None, timeout_seconds: float
    ) -> list[list[float]]:
        return [self._vector(text) for text in texts]

    def _vector(self, text: str) -> list[float]:
        vector = [0.0] * DIMENSIONS
        for token in text.lower().split():
            digest = hashlib.sha256(token.encode()).digest()
            index = int.from_bytes(digest[:4], "big") % DIMENSIONS
            sign = 1.0 if digest[4] % 2 == 0 else -1.0
            vector[index] += sign

        norm = math.sqrt(sum(v * v for v in vector)) or 1.0
        return [v / norm for v in vector]


class OpenAIEmbedder:
    name = "openai"

    def __init__(self, api_key: str) -> None:
        self._api_key = api_key

    async def embed(
        self, texts: list[str], model: str | None, timeout_seconds: float
    ) -> list[list[float]]:
        import openai

        client = openai.AsyncOpenAI(api_key=self._api_key, timeout=timeout_seconds)
        try:
            response = await client.embeddings.create(
                model=model or "text-embedding-3-small",
                input=texts,
            )
        except openai.OpenAIError as error:
            raise ProviderError(self.name, str(error)) from error

        return [item.embedding for item in response.data]


class GeminiEmbedder:
    """Google Gemini embeddings over REST (httpx). Uses gemini-embedding-001
    with outputDimensionality pinned to DIMENSIONS so vectors match the
    pgvector(1536) knowledge column exactly."""

    name = "gemini"

    _BASE_URL = "https://generativelanguage.googleapis.com/v1beta"

    def __init__(self, api_key: str, client: httpx.AsyncClient | None = None) -> None:
        self._api_key = api_key
        self._client = client  # injected in tests

    async def embed(
        self, texts: list[str], model: str | None, timeout_seconds: float
    ) -> list[list[float]]:
        model_name = model or "gemini-embedding-001"
        body = {
            "requests": [
                {
                    "model": f"models/{model_name}",
                    "content": {"parts": [{"text": text}]},
                    "outputDimensionality": DIMENSIONS,
                }
                for text in texts
            ]
        }

        client = self._client or httpx.AsyncClient(timeout=timeout_seconds)
        owns_client = self._client is None
        try:
            response = await client.post(
                f"{self._BASE_URL}/models/{model_name}:batchEmbedContents",
                headers={"x-goog-api-key": self._api_key},
                json=body,
            )
            response.raise_for_status()
            data = response.json()
        except (httpx.HTTPError, ValueError) as error:
            raise ProviderError(self.name, str(error)) from error
        finally:
            if owns_client:
                await client.aclose()

        return [item.get("values", []) for item in data.get("embeddings", [])]
