"""Backward-compatible Ollama-named import for the neutral AI-provider client.

ArchiBot is no longer architecturally coupled to native Ollama. New runtime and
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


__all__ = ["AiProviderClient", "OllamaClient", "_exc_to_str", "_strip_markdown_fences"]
