"""Tests for the neutral AI-provider seam."""

import asyncio
from unittest.mock import AsyncMock

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
        asyncio.run(provider.aclose())


def test_factory_provider_implements_pipeline_chat_gateway() -> None:
    provider = create_ai_provider(base_url="http://example.test", model="demo")
    provider.structured_json = AsyncMock(return_value={"title": "Invoice"})  # type: ignore[method-assign]
    provider.structured_vision_json = AsyncMock(return_value={"text": "A7K9"})  # type: ignore[method-assign]

    try:
        assert asyncio.run(provider.chat_json(system="system", user="document")) == {
            "title": "Invoice"
        }
        assert asyncio.run(
            provider.chat_vision_json("system", "document", ["image"])
        ) == {"text": "A7K9"}
        provider.structured_json.assert_awaited_once_with(
            "system",
            "document",
            model=None,
            temperature=0.1,
            num_ctx=None,
            role="classification",
        )
        provider.structured_vision_json.assert_awaited_once_with(
            "system",
            "document",
            ["image"],
            model=None,
            temperature=0.1,
            num_ctx=None,
            role="ocr",
        )
    finally:
        asyncio.run(provider.aclose())
