"""Durable pipeline run persistence helpers."""

from __future__ import annotations

from dataclasses import dataclass

from app.jobs.webhook_delivery import engine


@dataclass(frozen=True)
class PipelineRunRecord:
    id: int
    status: str


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


def upsert_document_pipeline_run(
    *,
    trigger_source: str,
    paperless_document_id: int,
    paperless_modified: str | None,
    content_hash: str | None,
    pipeline_dedupe_key: str,
    status: str,
    blocked_reason: str | None = None,
    reprocess_requested: bool = False,
    reprocess_reason: str | None = None,
    reprocess_mode: str | None = None,
) -> PipelineRunRecord:
    """Create or attach to the durable run for one document/dedupe key.

    The unique `(paperless_document_id, pipeline_dedupe_key)` constraint is the
    cross-trigger coalescing point for webhook, poll, manual, retry and reindex
    starts. Existing runs keep their current status; this function only adds the
    latest trigger source to `coalesced_sources` for operator visibility.
    """
    statement = sql_text(
        """
        INSERT INTO pipeline_runs (
            type,
            status,
            scope,
            trigger_source,
            paperless_document_id,
            paperless_modified,
            content_hash,
            pipeline_dedupe_key,
            coalesced_sources,
            progress_current_phase,
            progress_message,
            progress_updated_at,
            reprocess_requested,
            reprocess_reason,
            reprocess_mode,
            error_type,
            error,
            created_at,
            updated_at
        ) VALUES (
            'document',
            :status,
            'single_document',
            :trigger_source,
            :paperless_document_id,
            :paperless_modified,
            :content_hash,
            :pipeline_dedupe_key,
            jsonb_build_array(:trigger_source),
            :progress_current_phase,
            :progress_message,
            CURRENT_TIMESTAMP,
            :reprocess_requested,
            :reprocess_reason,
            :reprocess_mode,
            :error_type,
            :error,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
        ON CONFLICT (paperless_document_id, pipeline_dedupe_key)
        DO UPDATE SET
            coalesced_sources = CASE
                WHEN pipeline_runs.coalesced_sources IS NULL THEN jsonb_build_array(:trigger_source)
                WHEN pipeline_runs.coalesced_sources::jsonb ? :trigger_source THEN pipeline_runs.coalesced_sources::jsonb
                ELSE pipeline_runs.coalesced_sources::jsonb || jsonb_build_array(:trigger_source)
            END,
            reprocess_requested = pipeline_runs.reprocess_requested OR :reprocess_requested,
            reprocess_reason = COALESCE(:reprocess_reason, pipeline_runs.reprocess_reason),
            reprocess_mode = COALESCE(:reprocess_mode, pipeline_runs.reprocess_mode),
            updated_at = CURRENT_TIMESTAMP
        RETURNING id, status
        """
    )
    progress_phase = "blocked" if status == "blocked" else "queued"
    progress_message = (
        "Waiting for embedding index to complete."
        if blocked_reason == "embedding_index_not_ready"
        else "Waiting for document actor."
    )
    with engine().begin() as connection:
        row = (
            connection.execute(
                statement,
                {
                    "status": status,
                    "trigger_source": trigger_source,
                    "paperless_document_id": paperless_document_id,
                    "paperless_modified": paperless_modified,
                    "content_hash": content_hash,
                    "pipeline_dedupe_key": pipeline_dedupe_key,
                    "progress_current_phase": progress_phase,
                    "progress_message": progress_message,
                    "reprocess_requested": reprocess_requested,
                    "reprocess_reason": reprocess_reason,
                    "reprocess_mode": reprocess_mode,
                    "error_type": blocked_reason,
                    "error": progress_message if blocked_reason is not None else None,
                },
            )
            .mappings()
            .first()
        )

    if row is None:  # pragma: no cover - PostgreSQL RETURNING should always return here
        raise RuntimeError("pipeline run upsert did not return a row")

    return PipelineRunRecord(id=int(row["id"]), status=str(row["status"]))


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
        SET status = :status,
            progress_current_phase = COALESCE(:phase, progress_current_phase),
            progress_message = COALESCE(:message, progress_message),
            progress_updated_at = CURRENT_TIMESTAMP,
            error_type = :error_type,
            error = :error,
            started_at = CASE WHEN :status = 'running' AND started_at IS NULL THEN CURRENT_TIMESTAMP ELSE started_at END,
            finished_at = CASE WHEN :status IN ('succeeded', 'failed', 'blocked') THEN CURRENT_TIMESTAMP ELSE finished_at END,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :pipeline_run_id
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {
                "pipeline_run_id": pipeline_run_id,
                "status": status,
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
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :pipeline_run_id
        """
    )
    with engine().begin() as connection:
        connection.execute(statement, {"pipeline_run_id": pipeline_run_id, "message": message})
