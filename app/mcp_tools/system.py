"""System status retired because diagnostics require an admin-only PostgreSQL redaction seam."""

from __future__ import annotations

from mcp.server.fastmcp import FastMCP


def register(mcp: FastMCP) -> None:
    """Intentionally register nothing; see the MCP disposition matrix."""
    del mcp
