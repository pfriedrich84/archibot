"""Classification retired until a permission-aware Laravel Pipeline seam is available."""

from __future__ import annotations

from mcp.server.fastmcp import FastMCP


def register(mcp: FastMCP) -> None:
    """Intentionally register nothing; see the MCP disposition matrix."""
    del mcp
