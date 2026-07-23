"""Service configuration (env-driven; §18: keys via env/secret injection only)."""

from __future__ import annotations

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="AI_SERVICE_", env_file=".env", extra="ignore")

    # Shared bearer for internal callers (control plane). Mandatory — the
    # service refuses to start without it (fail closed, ADR-009).
    token: str

    anthropic_api_key: str | None = None
    openai_api_key: str | None = None


def load_settings() -> Settings:
    return Settings()  # type: ignore[call-arg]
