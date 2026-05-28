"""Factory for the configured AI-provider adapter."""

from app.ai_provider.client import AiProviderClient


def create_ai_provider(*, base_url: str | None = None, model: str | None = None) -> AiProviderClient:
    """Create the configured AI-provider adapter.

    Existing OLLAMA_* and OpenAI-compatible settings remain the source of
    configuration; this factory only gives runtime code a provider-neutral seam.
    """
    return AiProviderClient(base_url=base_url, model=model)
