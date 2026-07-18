"""Permission-scoped MCP server with no local product-state backend."""

from __future__ import annotations

import logging
import sys
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

import structlog
from mcp.server.fastmcp import FastMCP

from app.config import assert_product_database_config, settings
from app.mcp_tools._deps import Deps


def _configure_logging() -> None:
    log_level = getattr(logging, settings.log_level.upper(), logging.INFO)
    output = sys.stderr if settings.mcp_transport == "stdio" else sys.stdout
    structlog.configure(
        processors=[
            structlog.contextvars.merge_contextvars,
            structlog.processors.add_log_level,
            structlog.processors.TimeStamper(fmt="iso"),
            structlog.processors.JSONRenderer(),
        ],
        wrapper_class=structlog.make_filtering_bound_logger(log_level),
        context_class=dict,
        logger_factory=structlog.PrintLoggerFactory(file=output),
        cache_logger_on_first_use=True,
    )


@asynccontextmanager
async def lifespan(server: FastMCP) -> AsyncIterator[Deps]:
    """Start without a product-state backend or privileged global Paperless client."""
    del server
    assert_product_database_config()
    _configure_logging()
    yield Deps()


mcp = FastMCP(
    name="archibot",
    instructions=(
        "All legacy tools and resources are retired until permission-aware "
        "Laravel/PostgreSQL seams are available."
    ),
    lifespan=lifespan,
    host=settings.mcp_host,
    port=settings.mcp_port,
)

if __name__ == "__main__":
    mcp.run(transport=settings.mcp_transport)  # type: ignore[arg-type]
