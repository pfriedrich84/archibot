"""Durable pipeline run persistence helpers."""

from __future__ import annotations

from dataclasses import dataclass

from app.jobs.database import engine


@dataclass(frozen=True)
class DocumentPipelineRunRecord:
    id: int
    status: str
    paperless_document_id: int
    paperless_modified: str | None
    content_hash: str | None
    retry_count: int
    max_retries: int


def sql_text(statement: str):
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError("sqlalchemy is required for PostgreSQL-backed pipeline runs") from exc

    return text(statement)


def load_document_pipeline_run(pipeline_run_id: int) -> DocumentPipelineRunRecord | None:
    """Load the document-scoped fields needed by document actors."""
    statement = sql_text(
        """
        SELECT id, status, paperless_document_id, paperless_modified, content_hash, retry_count, max_retries
        FROM pipeline_runs
        WHERE id = :pipeline_run_id
          AND type = 'document'
          AND paperless_document_id IS NOT NULL
        """
    )
    with engine().connect() as connection:
        row = connection.execute(statement, {"pipeline_run_id": pipeline_run_id}).mappings().first()

    if row is None:
        return None

    paperless_modified = row["paperless_modified"]
    return DocumentPipelineRunRecord(
        id=int(row["id"]),
        status=str(row["status"]),
        paperless_document_id=int(row["paperless_document_id"]),
        paperless_modified=None if paperless_modified is None else str(paperless_modified),
        content_hash=None if row["content_hash"] is None else str(row["content_hash"]),
        retry_count=int(row["retry_count"]),
        max_retries=int(row["max_retries"]),
    )


def list_embedding_blocked_pipeline_run_ids(limit: int = 100) -> list[int]:
    """Return document runs blocked only by embedding-index readiness."""
    statement = sql_text(
        """
        SELECT id
        FROM pipeline_runs
        WHERE status = 'blocked'
          AND error_type = 'embedding_index_not_ready'
        ORDER BY updated_at ASC, id ASC
        LIMIT :limit
        """
    )
    with engine().connect() as connection:
        rows = connection.execute(statement, {"limit": limit}).mappings().all()

    return [int(row["id"]) for row in rows]


def list_cancel_requested_pipeline_run_ids(limit: int = 100) -> list[int]:
    """Return pipeline runs with administrator-requested cancellation."""
    statement = sql_text(
        """
        SELECT id
        FROM pipeline_runs
        WHERE status = 'cancel_requested'
        ORDER BY updated_at ASC, id ASC
        LIMIT :limit
        """
    )
    with engine().connect() as connection:
        rows = connection.execute(statement, {"limit": limit}).mappings().all()

    return [int(row["id"]) for row in rows]


def is_pipeline_run_cancel_requested(pipeline_run_id: int) -> bool:
    """Return True when a run is cancellation-requested or already cancelled."""
    statement = sql_text(
        """
        SELECT 1
        FROM pipeline_runs
        WHERE id = :pipeline_run_id
          AND status IN ('cancel_requested', 'cancelled')
        LIMIT 1
        """
    )
    with engine().connect() as connection:
        row = connection.execute(statement, {"pipeline_run_id": pipeline_run_id}).first()
    return row is not None


def mark_pipeline_run_cancelled(
    pipeline_run_id: int, message: str = "Pipeline run cancelled by admin request."
) -> None:
    """Finalize a cancellation request durably."""
    statement = sql_text(
        """
        UPDATE pipeline_runs
        SET status = 'cancelled',
            progress_message = :message,
            progress_updated_at = CURRENT_TIMESTAMP,
            error_type = 'cancelled',
            error = :message,
            finished_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :pipeline_run_id
        """
    )
    with engine().begin() as connection:
        connection.execute(statement, {"pipeline_run_id": pipeline_run_id, "message": message})


def list_pending_document_pipeline_run_ids(limit: int = 100) -> list[int]:
    """Return pending document pipeline runs ready for document actor enqueue."""
    statement = sql_text(
        """
        SELECT id
        FROM pipeline_runs
        WHERE type = 'document'
          AND status = 'pending'
        ORDER BY updated_at ASC, id ASC
        LIMIT :limit
        """
    )
    with engine().connect() as connection:
        rows = connection.execute(statement, {"limit": limit}).mappings().all()

    return [int(row["id"]) for row in rows]


def list_due_retrying_document_pipeline_run_ids(limit: int = 100) -> list[int]:
    """Return retrying document runs whose durable backoff has elapsed."""
    statement = sql_text(
        """
        SELECT id
        FROM pipeline_runs
        WHERE type = 'document'
          AND status = 'retrying'
          AND (next_retry_at IS NULL OR next_retry_at <= CURRENT_TIMESTAMP)
        ORDER BY COALESCE(next_retry_at, updated_at) ASC, id ASC
        LIMIT :limit
        """
    )
    with engine().connect() as connection:
        rows = connection.execute(statement, {"limit": limit}).mappings().all()

    return [int(row["id"]) for row in rows]


def mark_pipeline_run_retrying(
    pipeline_run_id: int,
    *,
    retry_class: str,
    retry_reason: str,
    backoff_seconds: int,
    phase: str | None = None,
    message: str | None = None,
) -> None:
    """Schedule a durable retry for a document pipeline run."""
    statement = sql_text(
        """
        UPDATE pipeline_runs
        SET status = 'retrying',
            retry_count = retry_count + 1,
            retry_reason = :retry_reason,
            retry_mode = 'automatic',
            last_retry_at = CURRENT_TIMESTAMP,
            next_retry_at = CURRENT_TIMESTAMP + (:backoff_seconds * INTERVAL '1 second'),
            progress_current_phase = COALESCE(:phase, progress_current_phase),
            progress_message = COALESCE(:message, progress_message),
            progress_updated_at = CURRENT_TIMESTAMP,
            error_type = :retry_class,
            error = :retry_reason,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :pipeline_run_id
          AND status NOT IN ('cancel_requested', 'cancelled')
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {
                "pipeline_run_id": pipeline_run_id,
                "retry_class": retry_class,
                "retry_reason": retry_reason,
                "backoff_seconds": backoff_seconds,
                "phase": phase,
                "message": message,
            },
        )


def mark_pipeline_run_status(
    pipeline_run_id: int,
    *,
    status: str,
    phase: str | None = None,
    message: str | None = None,
    error_type: str | None = None,
    error: str | None = None,
) -> None:
    """Update high-level pipeline run status and operator-facing state."""
    statement = sql_text(
        """
        UPDATE pipeline_runs
        SET status = CAST(:status AS character varying),
            progress_current_phase = COALESCE(:phase, progress_current_phase),
            progress_message = COALESCE(:message, progress_message),
            progress_updated_at = CURRENT_TIMESTAMP,
            error_type = :error_type,
            error = :error,
            started_at = CASE WHEN CAST(:status_for_lifecycle AS character varying) = 'running' AND started_at IS NULL THEN CURRENT_TIMESTAMP ELSE started_at END,
            finished_at = CASE WHEN CAST(:status_for_lifecycle AS character varying) IN ('succeeded', 'failed', 'blocked') THEN CURRENT_TIMESTAMP ELSE finished_at END,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :pipeline_run_id
          AND status NOT IN ('cancel_requested', 'cancelled')
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {
                "pipeline_run_id": pipeline_run_id,
                "status": status,
                "status_for_lifecycle": status,
                "phase": phase,
                "message": message,
                "error_type": error_type,
                "error": error,
            },
        )


def mark_pipeline_run_pending(
    pipeline_run_id: int, message: str = "Waiting for document actor."
) -> None:
    """Release a blocked pipeline run back to the pending queue."""
    statement = sql_text(
        """
        UPDATE pipeline_runs
        SET status = 'pending',
            progress_current_phase = 'queued',
            progress_message = :message,
            progress_updated_at = CURRENT_TIMESTAMP,
            error_type = NULL,
            error = NULL,
            finished_at = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :pipeline_run_id
        """
    )
    with engine().begin() as connection:
        connection.execute(statement, {"pipeline_run_id": pipeline_run_id, "message": message})
