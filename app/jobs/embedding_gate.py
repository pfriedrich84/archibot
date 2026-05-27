"""Embedding readiness gate contract."""

from __future__ import annotations

from app.jobs.database import engine


def sql_text(statement: str):
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError("sqlalchemy is required for PostgreSQL-backed embedding gates") from exc

    return text(statement)


def latest_embedding_index_status() -> str | None:
    """Return the newest durable embedding-index status from PostgreSQL."""
    statement = sql_text(
        """
        SELECT status
        FROM embedding_index_state
        ORDER BY completed_at DESC NULLS LAST, updated_at DESC, id DESC
        LIMIT 1
        """
    )
    with engine().connect() as connection:
        row = connection.execute(statement).mappings().first()

    if row is None:
        return None

    status = row["status"]
    return None if status is None else str(status)


def ensure_embedding_index_ready() -> bool:
    """Return whether document processing may start.

    Document processing is allowed only after the durable embedding index state
    is `complete`. Missing state, in-progress builds, failed builds and blocked
    database access all fail closed so webhook/poll/manual triggers can remain
    persisted without unsafe processing.
    """
    return latest_embedding_index_status() == "complete"
