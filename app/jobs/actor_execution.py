"""Durable actor execution tracking helpers."""

from __future__ import annotations

import time
from dataclasses import dataclass

from app.jobs.context import worker_id
from app.jobs.database import engine


@dataclass(frozen=True)
class ActorExecutionHandle:
    id: int | None
    actor_name: str
    started_monotonic: float
    attempt: int = 1


@dataclass(frozen=True)
class StaleActorExecutionRecord:
    id: int
    pipeline_run_id: int | None
    command_id: int | None
    webhook_delivery_id: int | None
    paperless_document_id: int | None
    actor_name: str
    attempt: int
    max_attempts: int


def sql_text(statement: str):
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError(
            "sqlalchemy is required for PostgreSQL-backed actor execution tracking"
        ) from exc

    return text(statement)


def start_actor_execution(
    *,
    actor_name: str,
    paperless_document_id: int | None = None,
    pipeline_run_id: int | None = None,
    command_id: int | None = None,
    webhook_delivery_id: int | None = None,
    message_id: str | None = None,
    queue_name: str | None = None,
    max_attempts: int = 5,
) -> ActorExecutionHandle:
    """Insert a durable running actor execution row and return its handle."""
    statement = sql_text(
        """
        WITH next_attempt AS (
            SELECT COALESCE(MAX(attempt), 0) + 1 AS attempt
            FROM actor_executions
            WHERE actor_name = :actor_name
              AND (
                    (CAST(:pipeline_run_id AS BIGINT) IS NOT NULL AND pipeline_run_id = CAST(:pipeline_run_id AS BIGINT))
                 OR (CAST(:command_id AS BIGINT) IS NOT NULL AND command_id = CAST(:command_id AS BIGINT))
                 OR (CAST(:webhook_delivery_id AS BIGINT) IS NOT NULL AND webhook_delivery_id = CAST(:webhook_delivery_id AS BIGINT))
              )
        )
        INSERT INTO actor_executions (
            pipeline_run_id,
            command_id,
            webhook_delivery_id,
            paperless_document_id,
            actor_name,
            message_id,
            queue_name,
            status,
            attempt,
            max_attempts,
            worker_id,
            started_at,
            progress_updated_at,
            created_at,
            updated_at
        ) SELECT
            :pipeline_run_id,
            :command_id,
            :webhook_delivery_id,
            :paperless_document_id,
            :actor_name,
            :message_id,
            :queue_name,
            'running',
            next_attempt.attempt,
            :max_attempts,
            :worker_id,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        FROM next_attempt
        RETURNING id, attempt
        """
    )
    with engine().begin() as connection:
        row = (
            connection.execute(
                statement,
                {
                    "pipeline_run_id": pipeline_run_id,
                    "command_id": command_id,
                    "webhook_delivery_id": webhook_delivery_id,
                    "paperless_document_id": paperless_document_id,
                    "actor_name": actor_name,
                    "message_id": message_id,
                    "queue_name": queue_name,
                    "max_attempts": max_attempts,
                    "worker_id": worker_id(),
                },
            )
            .mappings()
            .first()
        )

    return ActorExecutionHandle(
        id=None if row is None else int(row["id"]),
        actor_name=actor_name,
        started_monotonic=time.monotonic(),
        attempt=1 if row is None else int(row["attempt"]),
    )


def finish_actor_execution(
    handle: ActorExecutionHandle,
    *,
    status: str,
    error_type: str | None = None,
    error_message: str | None = None,
) -> None:
    """Mark a durable actor execution row finished."""
    if handle.id is None:
        return

    duration_ms = int((time.monotonic() - handle.started_monotonic) * 1000)
    statement = sql_text(
        """
        UPDATE actor_executions
        SET status = :status,
            finished_at = CURRENT_TIMESTAMP,
            duration_ms = :duration_ms,
            error_type = :error_type,
            error_message = :error_message,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :actor_execution_id
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {
                "actor_execution_id": handle.id,
                "status": status,
                "duration_ms": duration_ms,
                "error_type": error_type,
                "error_message": error_message,
            },
        )


def schedule_actor_execution_retry(
    handle: ActorExecutionHandle,
    *,
    retry_class: str,
    retry_reason: str,
    backoff_seconds: int,
    error_message: str | None = None,
) -> None:
    """Mark an actor execution retrying with durable backoff metadata."""
    if handle.id is None:
        return

    duration_ms = int((time.monotonic() - handle.started_monotonic) * 1000)
    statement = sql_text(
        """
        UPDATE actor_executions
        SET status = 'retrying',
            finished_at = CURRENT_TIMESTAMP,
            duration_ms = :duration_ms,
            retry_reason = :retry_reason,
            retry_mode = 'automatic',
            last_retry_at = CURRENT_TIMESTAMP,
            next_retry_at = CURRENT_TIMESTAMP + (:backoff_seconds * INTERVAL '1 second'),
            error_type = :retry_class,
            error_message = :error_message,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :actor_execution_id
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {
                "actor_execution_id": handle.id,
                "duration_ms": duration_ms,
                "retry_class": retry_class,
                "retry_reason": retry_reason,
                "backoff_seconds": backoff_seconds,
                "error_message": error_message,
            },
        )


def list_stale_running_actor_executions(
    *, stale_after_seconds: int = 900, limit: int = 100
) -> list[StaleActorExecutionRecord]:
    """Return actor executions that were running before the recovery threshold.

    Recovery uses these rows to repair work that was interrupted by a worker or
    container crash. A fresh running actor is left alone; only executions older
    than the threshold are considered stale.
    """
    statement = sql_text(
        """
        SELECT id,
               pipeline_run_id,
               command_id,
               webhook_delivery_id,
               paperless_document_id,
               actor_name,
               attempt,
               max_attempts
        FROM actor_executions
        WHERE status = 'running'
          AND started_at IS NOT NULL
          AND started_at < (CURRENT_TIMESTAMP - (:stale_after_seconds * INTERVAL '1 second'))
        ORDER BY started_at ASC, id ASC
        LIMIT :limit
        """
    )
    with engine().connect() as connection:
        rows = (
            connection.execute(
                statement,
                {"stale_after_seconds": stale_after_seconds, "limit": limit},
            )
            .mappings()
            .all()
        )

    records: list[StaleActorExecutionRecord] = []
    for row in rows:
        records.append(
            StaleActorExecutionRecord(
                id=int(row["id"]),
                pipeline_run_id=None
                if row["pipeline_run_id"] is None
                else int(row["pipeline_run_id"]),
                command_id=None if row["command_id"] is None else int(row["command_id"]),
                webhook_delivery_id=None
                if row["webhook_delivery_id"] is None
                else int(row["webhook_delivery_id"]),
                paperless_document_id=None
                if row["paperless_document_id"] is None
                else int(row["paperless_document_id"]),
                actor_name=str(row["actor_name"]),
                attempt=int(row["attempt"]),
                max_attempts=int(row["max_attempts"]),
            )
        )

    return records


def mark_stale_actor_execution_recovered(
    actor_execution_id: int,
    *,
    status: str = "retrying",
    error_type: str = "worker_recovery_stale_actor",
    error_message: str = "Actor execution was left running and recovered after worker restart.",
) -> None:
    """Mark a stale running actor execution as recovered by startup recovery."""
    statement = sql_text(
        """
        UPDATE actor_executions
        SET status = :status,
            finished_at = COALESCE(finished_at, CURRENT_TIMESTAMP),
            duration_ms = CASE
                WHEN started_at IS NULL THEN duration_ms
                ELSE CAST(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - started_at)) * 1000 AS INTEGER)
            END,
            retry_reason = :error_type,
            retry_mode = 'recovery',
            last_retry_at = CURRENT_TIMESTAMP,
            next_retry_at = CURRENT_TIMESTAMP,
            error_type = :error_type,
            error_message = :error_message,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :actor_execution_id
          AND status = 'running'
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {
                "actor_execution_id": actor_execution_id,
                "status": status,
                "error_type": error_type,
                "error_message": error_message,
            },
        )
