"""Identity-less FastMCP resources retired because they cannot enforce per-user permissions."""

from __future__ import annotations

from mcp.server.fastmcp import FastMCP


def register(mcp: FastMCP) -> None:
    """Intentionally register nothing; see the MCP disposition matrix."""
    del mcp
