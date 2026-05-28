"""Tests for the neutral AI-provider seam."""

from app.ai_provider import AiProviderClient, create_ai_provider
from app.clients.ollama import OllamaClient


def test_ollama_client_is_compatibility_wrapper() -> None:
    assert issubclass(OllamaClient, AiProviderClient)


def test_create_ai_provider_returns_neutral_client() -> None:
    provider = create_ai_provider(base_url="http://example.test", model="demo")
    try:
        assert isinstance(provider, AiProviderClient)
        assert provider.model == "demo"
        assert provider.base_url == "http://example.test"
    finally:
        # Avoid requiring an event loop in this simple construction test.
        provider._client = None  # type: ignore[assignment]
