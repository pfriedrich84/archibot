"""Compatibility facade for Python recovery transitions.

Productive redispatch through Absurd was retired by ADR-0017. Laravel database
queues claim every actor family. This module remains only for old CLI imports and
delegates all permitted transition work to :mod:`app.execution_lifecycle`.
"""

from __future__ import annotations

import structlog

from app import execution_lifecycle

log = structlog.get_logger(__name__)


def enqueue_embedding_build_command(command_id: int, limit: int | None = None) -> None:
    execution_lifecycle.retired_python_dispatch("embedding build recovery")


def enqueue_poll_reconciliation_command(command_id: int, limit: int | None = None) -> None:
    execution_lifecycle.retired_python_dispatch("poll reconciliation recovery")


def enqueue_reindex_command(command_id: int, limit: int | None = None) -> None:
    execution_lifecycle.retired_python_dispatch("reindex recovery")


def enqueue_ocr_reindex_command(
    command_id: int, limit: int | None = None, *, force: bool = False
) -> None:
    execution_lifecycle.retired_python_dispatch("OCR reindex recovery")


def enqueue_review_commit(review_suggestion_id: int, command_id: int | None = None) -> None:
    execution_lifecycle.retired_python_dispatch("review commit recovery")


def enqueue_review_commit_command(command_id: int, review_suggestion_id: int) -> None:
    execution_lifecycle.retired_python_dispatch("review commit command recovery")


def enqueue_document_pipeline_run(pipeline_run_id: int) -> None:
    execution_lifecycle.retired_python_dispatch("document recovery")


def enqueue_webhook_delivery(webhook_delivery_id: int) -> None:
    execution_lifecycle.retired_python_dispatch("webhook recovery")


def recover_stale_actor_executions(
    *, stale_after_seconds: int = 900, limit: int = 100
) -> tuple[int, int]:
    return (
        execution_lifecycle.recover_stale_executions(
            stale_after_seconds=stale_after_seconds, limit=limit
        ),
        0,
    )


def finalize_cancel_requested_runs(limit: int = 100) -> int:
    return execution_lifecycle.finalize_cancel_requests(limit=limit)


def release_embedding_blocked_runs(limit: int = 100) -> int:
    return 0


def release_embedding_blocked_webhooks(limit: int = 100) -> int:
    return 0


def run_recovery_scan(limit: int = 100) -> None:
    recovered, cancelled = execution_lifecycle.run_recovery_transition_scan(limit=limit)
    log.info(
        "python recovery transition scan completed; Laravel owns redispatch",
        actor_executions_recovered=recovered,
        pipeline_runs_cancelled=cancelled,
        redispatched=0,
    )
