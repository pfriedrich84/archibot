"""Shared PostgreSQL connection helpers for durable job state."""

from __future__ import annotations

from typing import TYPE_CHECKING

from app.config import settings

if TYPE_CHECKING:
    from sqlalchemy.engine import Engine

_engine: Engine | None = None


def engine() -> Engine:
    """Return the shared SQLAlchemy engine for PostgreSQL-backed job helpers."""
    global _engine
    if _engine is None:
        try:
            from sqlalchemy import create_engine
        except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
            raise RuntimeError("sqlalchemy is required for PostgreSQL-backed job state") from exc

        _engine = create_engine(settings.database_url, pool_pre_ping=True)
    return _engine
