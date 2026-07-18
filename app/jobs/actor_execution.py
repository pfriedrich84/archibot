"""Durable actor execution tracking helpers."""

from __future__ import annotations

import time
from dataclasses import dataclass

from app.jobs.context import worker_id
from app.jobs.database import engine


@dataclass(frozen=True)
class _PostgresqlActorExecutionSql:
    """Closed productive SQL dialect; tests may replace this module-local adapter."""

    source_lock_clause = " FOR UPDATE"
    retry_timestamp = "CURRENT_TIMESTAMP + (:backoff_seconds * INTERVAL '1 second')"


_sql = _PostgresqlActorExecutionSql()


@dataclass(frozen=True)
class ActorExecutionHandle:
    id: int | None
    actor_name: str
    started_monotonic: float
    attempt: int = 1
    execution_token: str | None = None
    source_kind: str | None = None
    source_id: int | None = None
    source_version: int | None = None


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
    execution_token: str | None = None
    source_version: int | None = None


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
    execution_token: str | None = None,
    source_version: int | None = None,
    actor_execution_id: int | None = None,
    expected_attempt: int | None = None,
) -> ActorExecutionHandle:
    """Activate exactly one Laravel-created attempt under a locked source fence."""
    sources = [
        ("pipeline_run", "pipeline_runs", "pipeline_run_id", pipeline_run_id),
        ("command", "commands", "command_id", command_id),
        ("webhook_delivery", "webhook_deliveries", "webhook_delivery_id", webhook_delivery_id),
    ]
    selected = [entry for entry in sources if entry[3] is not None]
    if execution_token is not None:
        if (
            len(selected) != 1
            or source_version is None
            or actor_execution_id is None
            or expected_attempt is None
        ):
            raise RuntimeError(
                "fenced actor execution requires one source, version, id, and attempt"
            )
        source_kind, table, column, source_id = selected[0]
        with engine().begin() as connection:
            source = (
                connection.execute(
                    sql_text(
                        f"SELECT status, lifecycle_version, active_actor_token FROM {table} WHERE id = :source_id{_sql.source_lock_clause}"
                    ),
                    {"source_id": source_id},
                )
                .mappings()
                .first()
            )
            if (
                source is None
                or int(source["lifecycle_version"]) != source_version
                or source["active_actor_token"] != execution_token
                or str(source["status"]) != "running"
            ):
                raise RuntimeError("actor source fence is stale or no longer active")
            row = (
                connection.execute(
                    sql_text(f"""
                UPDATE actor_executions SET status = 'running', worker_id = :worker_id,
                    started_at = COALESCE(started_at, CURRENT_TIMESTAMP),
                    progress_updated_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                WHERE id = :actor_execution_id AND execution_token = :execution_token
                  AND source_version = :source_version AND actor_name = :actor_name
                  AND attempt = :attempt AND status = 'queued' AND {column} = :source_id
                RETURNING id, attempt
            """),
                    {
                        "actor_execution_id": actor_execution_id,
                        "execution_token": execution_token,
                        "source_version": source_version,
                        "actor_name": actor_name,
                        "attempt": expected_attempt,
                        "source_id": source_id,
                        "worker_id": worker_id(),
                    },
                )
                .mappings()
                .first()
            )
            if row is None:
                raise RuntimeError("actor execution claim was already consumed or mismatched")
        return ActorExecutionHandle(
            int(row["id"]),
            actor_name,
            time.monotonic(),
            int(row["attempt"]),
            execution_token,
            source_kind,
            int(source_id),
            source_version,
        )

    statement = sql_text("""
        WITH next_attempt AS (
            SELECT COALESCE(MAX(attempt), 0) + 1 AS attempt
            FROM actor_executions
            WHERE actor_name = :actor_name
              AND ((:pipeline_run_id IS NOT NULL AND pipeline_run_id = :pipeline_run_id)
                OR (:command_id IS NOT NULL AND command_id = :command_id)
                OR (:webhook_delivery_id IS NOT NULL AND webhook_delivery_id = :webhook_delivery_id))
        )
        INSERT INTO actor_executions (pipeline_run_id, command_id, webhook_delivery_id,
            paperless_document_id, actor_name, message_id, queue_name, status, attempt,
            max_attempts, worker_id, started_at, progress_updated_at, created_at, updated_at)
        SELECT :pipeline_run_id, :command_id, :webhook_delivery_id, :paperless_document_id,
            :actor_name, :message_id, :queue_name, 'running', next_attempt.attempt,
            :max_attempts, :worker_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM next_attempt
        RETURNING id, attempt
    """)
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
        None if row is None else int(row["id"]),
        actor_name,
        time.monotonic(),
        1 if row is None else int(row["attempt"]),
    )


def _transition_source_for_execution(
    connection,
    handle: ActorExecutionHandle,
    execution_status: str,
    *,
    error_type: str | None = None,
) -> None:
    """Apply the execution outcome to its still-active durable source exactly once."""
    if handle.execution_token is None or handle.source_kind is None or handle.source_id is None:
        return
    table = {
        "pipeline_run": "pipeline_runs",
        "command": "commands",
        "webhook_delivery": "webhook_deliveries",
    }[handle.source_kind]
    status = {
        "pipeline_run": {
            "succeeded": "succeeded",
            "skipped": "skipped",
            "blocked": "blocked",
            "cancelled": "cancelled",
            "failed_permanent": "failed_permanent",
            "retrying": "retrying",
        },
        "command": {
            "succeeded": "succeeded",
            "skipped": "skipped",
            "blocked": "blocked",
            "cancelled": "cancelled",
            "failed_permanent": "failed_permanent",
            "retrying": "pending",
        },
        "webhook_delivery": {
            "succeeded": "processed",
            "skipped": "dismissed",
            "blocked": "blocked",
            "cancelled": "failed_permanent",
            "failed_permanent": "failed_permanent",
            "retrying": "failed",
        },
    }[handle.source_kind].get(execution_status)
    if status is None:
        raise ValueError(f"unsupported source outcome: {execution_status}")
    finished = (
        ", finished_at = CURRENT_TIMESTAMP"
        if handle.source_kind in {"pipeline_run", "command"} and execution_status != "retrying"
        else ""
    )
    retry_count = (
        ", retry_count = retry_count + 1, retry_reason = :error_type, retry_mode = 'automatic'"
        if handle.source_kind == "pipeline_run" and execution_status == "retrying"
        else ""
    )
    result = connection.execute(
        sql_text(
            f"""UPDATE {table} SET status = :status, error = :error_type
                {finished} {retry_count}, updated_at = CURRENT_TIMESTAMP
                WHERE id = :source_id AND status = 'running'
                  AND lifecycle_version = :source_version
                  AND active_actor_token = :execution_token"""
        ),
        {
            "status": status,
            "error_type": error_type,
            "source_id": handle.source_id,
            "source_version": handle.source_version,
            "execution_token": handle.execution_token,
        },
    )
    if result.rowcount != 1:
        raise RuntimeError("stale actor attempt cannot transition its durable source")


def _release_source_fence(
    connection, handle: ActorExecutionHandle, backoff_seconds: int | None = None
) -> None:
    if handle.execution_token is None or handle.source_kind is None or handle.source_id is None:
        return
    table = {
        "pipeline_run": "pipeline_runs",
        "command": "commands",
        "webhook_delivery": "webhook_deliveries",
    }[handle.source_kind]
    if backoff_seconds is None:
        retry_assignment = ", next_retry_at = NULL"
    else:
        retry_assignment = f", next_retry_at = {_sql.retry_timestamp}"
    connection.execute(
        sql_text(f"""UPDATE {table} SET active_actor_token = NULL {retry_assignment}, updated_at = CURRENT_TIMESTAMP
            WHERE id = :source_id AND lifecycle_version = :source_version
              AND active_actor_token = :execution_token"""),
        {
            "source_id": handle.source_id,
            "source_version": handle.source_version,
            "execution_token": handle.execution_token,
            "backoff_seconds": backoff_seconds,
        },
    )


def finish_actor_execution(
    handle: ActorExecutionHandle,
    *,
    status: str,
    error_type: str | None = None,
    error_message: str | None = None,
) -> bool:
    """Atomically finish execution and source; return whether this call won."""
    if handle.id is None:
        return False

    duration_ms = int((time.monotonic() - handle.started_monotonic) * 1000)
    statement = sql_text("""
        UPDATE actor_executions
        SET status = :status, finished_at = CURRENT_TIMESTAMP,
            duration_ms = :duration_ms, error_type = :error_type,
            error_message = :error_message, next_retry_at = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :actor_execution_id AND status = 'running'
          AND (:execution_token IS NULL OR execution_token = :execution_token)
          AND (:source_version IS NULL OR source_version = :source_version)
        RETURNING status
    """)
    params = {
        "actor_execution_id": handle.id,
        "status": status,
        "duration_ms": duration_ms,
        "error_type": error_type,
        "error_message": error_message,
        "execution_token": handle.execution_token,
        "source_version": handle.source_version,
    }
    with engine().begin() as connection:
        row = connection.execute(statement, params).mappings().first()
        if row is None:
            existing = (
                connection.execute(
                    sql_text(
                        "SELECT status, execution_token, source_version FROM actor_executions WHERE id = :id"
                    ),
                    {"id": handle.id},
                )
                .mappings()
                .first()
            )
            if (
                existing is None
                or str(existing["status"]) != status
                or (
                    handle.execution_token is not None
                    and existing["execution_token"] != handle.execution_token
                )
                or (
                    handle.source_version is not None
                    and int(existing["source_version"]) != handle.source_version
                )
            ):
                raise RuntimeError("stale actor attempt cannot finalize execution")
            # An identical completed attempt is an idempotent replay. Its
            # source fence was already released by the first transaction.
            return False
        _transition_source_for_execution(connection, handle, status, error_type=error_type)
        _release_source_fence(connection, handle)
    return True


def schedule_actor_execution_retry(
    handle: ActorExecutionHandle,
    *,
    retry_class: str,
    retry_reason: str,
    backoff_seconds: int,
    error_message: str | None = None,
) -> bool:
    """Atomically schedule execution/source retry; return whether this call won."""
    if handle.id is None:
        return False

    duration_ms = int((time.monotonic() - handle.started_monotonic) * 1000)
    statement = sql_text(f"""
        UPDATE actor_executions
        SET status = 'retrying', finished_at = CURRENT_TIMESTAMP,
            duration_ms = :duration_ms, retry_reason = :retry_reason,
            retry_mode = 'automatic', last_retry_at = CURRENT_TIMESTAMP,
            next_retry_at = {_sql.retry_timestamp},
            error_type = :retry_class, error_message = :error_message,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :actor_execution_id AND status = 'running'
          AND (:execution_token IS NULL OR execution_token = :execution_token)
          AND (:source_version IS NULL OR source_version = :source_version)
        RETURNING status
    """)
    params = {
        "actor_execution_id": handle.id,
        "duration_ms": duration_ms,
        "retry_class": retry_class,
        "retry_reason": retry_reason,
        "backoff_seconds": backoff_seconds,
        "error_message": error_message,
        "execution_token": handle.execution_token,
        "source_version": handle.source_version,
    }
    with engine().begin() as connection:
        row = connection.execute(statement, params).mappings().first()
        if row is None:
            existing = (
                connection.execute(
                    sql_text(
                        "SELECT status, execution_token, source_version FROM actor_executions WHERE id = :id"
                    ),
                    {"id": handle.id},
                )
                .mappings()
                .first()
            )
            if (
                existing is None
                or str(existing["status"]) != "retrying"
                or (
                    handle.execution_token is not None
                    and existing["execution_token"] != handle.execution_token
                )
                or (
                    handle.source_version is not None
                    and int(existing["source_version"]) != handle.source_version
                )
            ):
                raise RuntimeError("stale actor attempt cannot schedule retry")
            return False
        _transition_source_for_execution(connection, handle, "retrying", error_type=retry_class)
        _release_source_fence(connection, handle, backoff_seconds)
    return True


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
               max_attempts,
               execution_token,
               source_version
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
                execution_token=None
                if row["execution_token"] is None
                else str(row["execution_token"]),
                source_version=None
                if row["source_version"] is None
                else int(row["source_version"]),
            )
        )

    return records
