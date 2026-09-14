"""Embedding readiness gate contract."""

from __future__ import annotations

from app.config import settings
from app.jobs.database import engine


def sql_text(statement: str):
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError("sqlalchemy is required for PostgreSQL-backed embedding gates") from exc

    return text(statement)


def latest_embedding_index_status(embedding_model: str | None = None) -> str | None:
    """Return the newest durable status for the configured embedding model."""
    selected_model = embedding_model or settings.ollama_embed_model
    statement = sql_text(
        """
        SELECT status
        FROM embedding_index_state
        WHERE embedding_model = :embedding_model
        ORDER BY completed_at DESC NULLS LAST, updated_at DESC, id DESC
        LIMIT 1
        """
    )
    with engine().connect() as connection:
        row = connection.execute(statement, {"embedding_model": selected_model}).mappings().first()

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
