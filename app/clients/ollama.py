"""Backward-compatible Ollama-named import for the neutral AI-provider client.

ArchiBot is no longer architecturally coupled to a specific Ollama runtime. New runtime and
processing code should import from :mod:`app.ai_provider`; this module remains
for compatibility with existing tests, plugins, and older call sites.
"""

from app.ai_provider.client import (
    AiProviderClient,
    _exc_to_str,
    _strip_markdown_fences,
)


class OllamaClient(AiProviderClient):
    """Compatibility wrapper for the neutral :class:`AiProviderClient`."""

    async def chat_json(self, *args, **kwargs):
        return await self.structured_json(*args, **kwargs)

    async def chat_vision_json(self, *args, **kwargs):
        return await self.structured_vision_json(*args, **kwargs)


__all__ = ["AiProviderClient", "OllamaClient", "_exc_to_str", "_strip_markdown_fences"]
