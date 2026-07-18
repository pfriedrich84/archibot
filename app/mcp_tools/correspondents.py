"""Correspondent proposal operations retired; direct SQLite and Paperless mutations are forbidden."""

from __future__ import annotations

from mcp.server.fastmcp import FastMCP


def register(mcp: FastMCP) -> None:
    """Intentionally register nothing; see the MCP disposition matrix."""
    del mcp
