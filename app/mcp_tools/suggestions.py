"""Suggestion reads and decisions retired until the Laravel Review seam is exposed to MCP."""

from __future__ import annotations

from mcp.server.fastmcp import FastMCP


def register(mcp: FastMCP) -> None:
    """Intentionally register nothing; see the MCP disposition matrix."""
    del mcp
