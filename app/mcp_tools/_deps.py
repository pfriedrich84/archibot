"""Typed dependency container for MCP tool context."""

from __future__ import annotations

from dataclasses import dataclass

from mcp.server.fastmcp import Context

from app.clients.ollama import OllamaClient
from app.clients.paperless import PaperlessClient
from app.mcp_tools._auth import McpIdentity, RateLimiter, get_mcp_identity


@dataclass
class Deps:
    paperless: PaperlessClient
    ollama: OllamaClient
    rate_limiter: RateLimiter


def get_identity(ctx: Context) -> McpIdentity | None:
    """Return the verified MCP caller identity attached by auth checks."""
    return get_mcp_identity(ctx)


def get_deps(ctx: Context) -> Deps:
    """Extract Deps from the MCP lifespan context."""
    return ctx.request_context.lifespan_context
