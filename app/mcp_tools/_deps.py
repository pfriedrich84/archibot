"""Typed dependency marker for the dormant MCP runtime."""

from __future__ import annotations

from dataclasses import dataclass

from mcp.server.fastmcp import Context

from app.mcp_tools._auth import McpIdentity, get_mcp_identity


@dataclass
class Deps:
    """Lifespan marker; no product-state client is created at startup."""


def get_identity(ctx: Context) -> McpIdentity | None:
    """Return an identity previously attached by the fail-closed auth guard."""
    return get_mcp_identity(ctx)
