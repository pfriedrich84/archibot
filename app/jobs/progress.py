"""Durable progress helper contracts.

Progress must be stored in PostgreSQL and derived from item state where possible.
This module centralizes the helper names actors should use for durable command and
pipeline progress.
"""

from __future__ import annotations

from dataclasses import dataclass

from app.jobs.database import engine


@dataclass(frozen=True)
class ProgressSnapshot:
    total: int = 0
    done: int = 0
    failed: int = 0
    skipped: int = 0
    phase: str | None = None
    message: str | None = None


def sql_text(statement: str):
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError(
            "sqlalchemy is required for PostgreSQL-backed progress tracking"
        ) from exc

    return text(statement)


def update_pipeline_run_progress(pipeline_run_id: int, snapshot: ProgressSnapshot) -> None:
    """Persist a pipeline-level progress snapshot."""
    from app.execution_lifecycle import source_fence

    fence_sql, fence_params = source_fence("pipeline_run", pipeline_run_id)
    statement = sql_text(
        f"""
        UPDATE pipeline_runs
        SET progress_total = :total,
            progress_done = :done,
            progress_failed = :failed,
            progress_skipped = :skipped,
            progress_current_phase = :phase,
            progress_message = :message,
            progress_updated_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :pipeline_run_id {fence_sql}
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {
                "pipeline_run_id": pipeline_run_id,
                "total": snapshot.total,
                "done": snapshot.done,
                "failed": snapshot.failed,
                "skipped": snapshot.skipped,
                "phase": snapshot.phase,
                "message": snapshot.message,
                **fence_params,
            },
        )


def update_actor_execution_progress(
    actor_execution_id: int, snapshot: ProgressSnapshot, current_item: str | None = None
) -> None:
    """Persist an actor-level progress snapshot."""
    statement = sql_text(
        """
        UPDATE actor_executions
        SET progress_total = :total,
            progress_done = :done,
            progress_failed = :failed,
            progress_skipped = :skipped,
            progress_current_item = :current_item,
            progress_message = :message,
            progress_updated_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :actor_execution_id AND status = 'running'
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {
                "actor_execution_id": actor_execution_id,
                "total": snapshot.total,
                "done": snapshot.done,
                "failed": snapshot.failed,
                "skipped": snapshot.skipped,
                "current_item": current_item or snapshot.phase,
                "message": snapshot.message,
            },
        )
