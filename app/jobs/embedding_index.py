"""Durable embedding-index state helpers."""

from __future__ import annotations

from dataclasses import dataclass

from app.jobs.database import engine


@dataclass(frozen=True)
class EmbeddingIndexBuild:
    id: int
    status: str


def sql_text(statement: str):
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError(
            "sqlalchemy is required for PostgreSQL-backed embedding index state"
        ) from exc

    return text(statement)


def start_embedding_index_build(
    *,
    embedding_model: str | None,
    dimensions: int | None,
    content_scope: str | None,
    document_count: int = 0,
) -> EmbeddingIndexBuild:
    """Create a durable embedding-index build row in `building` state."""
    statement = sql_text(
        """
        INSERT INTO embedding_index_state (
            status,
            embedding_model,
            dimensions,
            content_scope,
            started_at,
            document_count,
            embedded_count,
            failed_count,
            created_at,
            updated_at
        ) VALUES (
            'building',
            :embedding_model,
            :dimensions,
            :content_scope,
            CURRENT_TIMESTAMP,
            :document_count,
            0,
            0,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
        RETURNING id, status
        """
    )
    with engine().begin() as connection:
        row = (
            connection.execute(
                statement,
                {
                    "embedding_model": embedding_model,
                    "dimensions": dimensions,
                    "content_scope": content_scope,
                    "document_count": document_count,
                },
            )
            .mappings()
            .first()
        )

    if row is None:  # pragma: no cover - PostgreSQL RETURNING should always return here
        raise RuntimeError("embedding index build insert did not return a row")

    return EmbeddingIndexBuild(id=int(row["id"]), status=str(row["status"]))


def update_embedding_index_progress(
    build_id: int,
    *,
    document_count: int,
    embedded_count: int,
    failed_count: int,
) -> None:
    """Persist restart-safe embedding build progress."""
    statement = sql_text(
        """
        UPDATE embedding_index_state
        SET document_count = :document_count,
            embedded_count = :embedded_count,
            failed_count = :failed_count,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :build_id
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {
                "build_id": build_id,
                "document_count": document_count,
                "embedded_count": embedded_count,
                "failed_count": failed_count,
            },
        )


def finish_embedding_index_build(build_id: int, *, status: str, error: str | None = None) -> None:
    """Mark an embedding-index build complete or failed."""
    statement = sql_text(
        """
        UPDATE embedding_index_state
        SET status = :status,
            completed_at = CASE WHEN :status IN ('complete', 'failed') THEN CURRENT_TIMESTAMP ELSE completed_at END,
            error = :error,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :build_id
        """
    )
    with engine().begin() as connection:
        connection.execute(statement, {"build_id": build_id, "status": status, "error": error})
