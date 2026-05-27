"""Durable pipeline item helpers."""

from __future__ import annotations

from dataclasses import dataclass

from app.jobs.database import engine


@dataclass(frozen=True)
class PipelineItemRecord:
    id: int
    status: str
    attempt: int = 1


def sql_text(statement: str):
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError("sqlalchemy is required for PostgreSQL-backed pipeline items") from exc

    return text(statement)


def start_pipeline_item(
    *,
    pipeline_run_id: int,
    item_type: str,
    paperless_document_id: int | None = None,
    max_attempts: int = 5,
) -> PipelineItemRecord:
    """Create a running item row for a retry-safe pipeline step."""
    statement = sql_text(
        """
        INSERT INTO pipeline_items (
            pipeline_run_id,
            paperless_document_id,
            item_type,
            status,
            attempt,
            max_attempts,
            started_at,
            created_at,
            updated_at
        ) VALUES (
            :pipeline_run_id,
            :paperless_document_id,
            :item_type,
            'running',
            1,
            :max_attempts,
            CURRENT_TIMESTAMP,
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
                    "pipeline_run_id": pipeline_run_id,
                    "paperless_document_id": paperless_document_id,
                    "item_type": item_type,
                    "max_attempts": max_attempts,
                },
            )
            .mappings()
            .first()
        )

    if row is None:  # pragma: no cover - PostgreSQL RETURNING should always return here
        raise RuntimeError("pipeline item insert did not return a row")

    return PipelineItemRecord(
        id=int(row["id"]), status=str(row["status"]), attempt=int(row.get("attempt", 1))
    )


def start_or_resume_pipeline_item(
    *,
    pipeline_run_id: int,
    item_type: str,
    item_key: str,
    paperless_document_id: int | None = None,
    max_attempts: int = 5,
) -> PipelineItemRecord:
    """Start or resume a phase item identified by a stable per-run key."""
    statement = sql_text(
        """
        INSERT INTO pipeline_items (
            pipeline_run_id,
            paperless_document_id,
            item_type,
            item_key,
            status,
            attempt,
            max_attempts,
            started_at,
            finished_at,
            error,
            created_at,
            updated_at
        ) VALUES (
            :pipeline_run_id,
            :paperless_document_id,
            :item_type,
            :item_key,
            'running',
            1,
            :max_attempts,
            CURRENT_TIMESTAMP,
            NULL,
            NULL,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
        ON CONFLICT (pipeline_run_id, item_key)
        DO UPDATE SET
            status = 'running',
            attempt = pipeline_items.attempt + 1,
            max_attempts = EXCLUDED.max_attempts,
            started_at = CURRENT_TIMESTAMP,
            finished_at = NULL,
            error = NULL,
            updated_at = CURRENT_TIMESTAMP
        RETURNING id, status, attempt
        """
    )
    with engine().begin() as connection:
        row = (
            connection.execute(
                statement,
                {
                    "pipeline_run_id": pipeline_run_id,
                    "paperless_document_id": paperless_document_id,
                    "item_type": item_type,
                    "item_key": item_key,
                    "max_attempts": max_attempts,
                },
            )
            .mappings()
            .first()
        )

    if row is None:  # pragma: no cover - PostgreSQL RETURNING should always return here
        raise RuntimeError("pipeline item upsert did not return a row")

    return PipelineItemRecord(id=int(row["id"]), status=str(row["status"]), attempt=int(row["attempt"]))


def finish_pipeline_item(item_id: int, *, status: str, error: str | None = None) -> None:
    """Mark a pipeline item succeeded, failed or skipped."""
    statement = sql_text(
        """
        UPDATE pipeline_items
        SET status = :status,
            error = :error,
            finished_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :item_id
        """
    )
    with engine().begin() as connection:
        connection.execute(statement, {"item_id": item_id, "status": status, "error": error})


def progress_from_pipeline_items(pipeline_run_id: int) -> tuple[int, int, int, int]:
    """Derive progress counters from durable item state."""
    statement = sql_text(
        """
        SELECT
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE status = 'succeeded') AS done,
            COUNT(*) FILTER (WHERE status = 'failed') AS failed,
            COUNT(*) FILTER (WHERE status = 'skipped') AS skipped
        FROM pipeline_items
        WHERE pipeline_run_id = :pipeline_run_id
        """
    )
    with engine().connect() as connection:
        row = connection.execute(statement, {"pipeline_run_id": pipeline_run_id}).mappings().first()

    if row is None:
        return (0, 0, 0, 0)

    return (int(row["total"]), int(row["done"]), int(row["failed"]), int(row["skipped"]))
