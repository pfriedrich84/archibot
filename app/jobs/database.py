"""Shared PostgreSQL connection helpers for durable job state."""

from __future__ import annotations

from typing import TYPE_CHECKING

from app.config import require_postgresql_database_url, settings

if TYPE_CHECKING:
    from sqlalchemy.engine import Engine

_engine: Engine | None = None


def engine() -> Engine:
    """Return the shared product engine, which can only target PostgreSQL."""
    global _engine
    if _engine is None:
        database_url = require_postgresql_database_url(settings.database_url)
        try:
            from sqlalchemy import create_engine
        except (
            ModuleNotFoundError
        ) as exc:  # pragma: no cover - dependency is installed in target image
            raise RuntimeError("sqlalchemy is required for PostgreSQL-backed job state") from exc

        candidate = create_engine(database_url, pool_pre_ping=True)
        if candidate.dialect.name != "postgresql":
            candidate.dispose()
            raise RuntimeError("The product database engine must use PostgreSQL")
        _engine = candidate
    return _engine
