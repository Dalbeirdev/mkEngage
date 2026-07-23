"""Embedding providers (ADR-003 embedding routing).

FakeEmbedder: deterministic 1536-dim vectors from token hashes — no network,
stable across runs, and crucially SIMILAR TEXTS GET SIMILAR VECTORS (bag of
hashed words), so retrieval ordering is meaningful in tests and demos.
"""

from __future__ import annotations

import hashlib
import math
from typing import Protocol

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
