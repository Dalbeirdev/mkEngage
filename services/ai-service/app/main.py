"""mkEngage AI service (ADR-003) — Phase 6 scope: chatbot reply generation
behind the provider abstraction. RAG, agent tools, budgets, and fallback
chains land in later phases per the ADR."""

from __future__ import annotations

import hmac
import logging
from typing import Annotated

from fastapi import Depends, FastAPI, HTTPException, Request
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from app.config import Settings, load_settings
from app.providers.base import ModelProvider, ProviderError
from app.providers.fake import FakeProvider
from app.schemas import ReplyRequest, ReplyResponse

logger = logging.getLogger("ai-service")

app = FastAPI(title="mkEngage AI Service", version="0.1.0", docs_url=None, redoc_url=None)

_bearer = HTTPBearer(auto_error=False)


def get_settings(request: Request) -> Settings:
    settings = getattr(request.app.state, "settings", None)
    if settings is None:
        settings = load_settings()
        request.app.state.settings = settings
    return settings


def require_internal_auth(
    credentials: Annotated[HTTPAuthorizationCredentials | None, Depends(_bearer)],
    settings: Annotated[Settings, Depends(get_settings)],
) -> None:
    if credentials is None or not hmac.compare_digest(credentials.credentials, settings.token):
        raise HTTPException(status_code=401, detail="Unauthenticated")


def resolve_provider(name: str, settings: Settings) -> ModelProvider:
    if name == "fake":
        return FakeProvider()
    if name == "anthropic":
        if settings.anthropic_api_key is None:
            raise HTTPException(status_code=422, detail="Anthropic is not configured")
        from app.providers.anthropic_provider import AnthropicProvider

        return AnthropicProvider(settings.anthropic_api_key)
    if name == "openai":
        if settings.openai_api_key is None:
            raise HTTPException(status_code=422, detail="OpenAI is not configured")
        from app.providers.openai_provider import OpenAIProvider

        return OpenAIProvider(settings.openai_api_key)
    raise HTTPException(status_code=422, detail=f"Unknown provider: {name}")


@app.get("/health")
async def health() -> dict[str, str]:
    return {"status": "ok", "service": "ai-service"}


@app.post("/v1/reply", dependencies=[Depends(require_internal_auth)])
async def reply(
    payload: ReplyRequest,
    settings: Annotated[Settings, Depends(get_settings)],
) -> ReplyResponse:
    provider = resolve_provider(payload.config.provider, settings)

    try:
        completion = await provider.complete(
            system_prompt=payload.system_prompt,
            history=payload.history,
            model=payload.config.model,
            max_output_tokens=payload.config.max_output_tokens,
            timeout_seconds=payload.config.timeout_seconds,
        )
    except ProviderError as error:
        # Telemetry-safe: provider + status only, never message bodies (ADR-008).
        logger.warning(
            "provider_failure provider=%s org=%s", error.provider, payload.organization_id
        )
        raise HTTPException(status_code=502, detail="Provider request failed") from error

    return ReplyResponse(
        body=completion.body,
        provider=provider.name,
        model=completion.model,
        input_tokens=completion.input_tokens,
        output_tokens=completion.output_tokens,
    )
