"""Factory for the configured AI-provider adapter."""

from app.ai_provider.client import AiProviderClient


def create_ai_provider(
    *,
    base_url: str | None = None,
    model: str | None = None,
    provider_type: str | None = None,
    embed_model: str | None = None,
    embed_num_ctx: int | None = None,
    ocr_model: str | None = None,
) -> AiProviderClient:
    """Create the configured AI-provider adapter.

    Existing OLLAMA_* and OpenAI-compatible settings remain the source of
    configuration; this factory only gives runtime code a provider-neutral seam.
    """
    from app.clients.ollama import OllamaClient

    return OllamaClient(
        base_url=base_url,
        model=model,
        provider_type=provider_type,
        embed_model=embed_model,
        embed_num_ctx=embed_num_ctx,
        ocr_model=ocr_model,
    )
