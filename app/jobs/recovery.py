"""Recovery scan skeleton for restart-safe Dramatiq workers."""

from __future__ import annotations

import structlog

from app.actors.document import handle_document_pipeline
from app.actors.embedding import build_initial_embedding_index
from app.actors.maintenance import reconcile_inbox_documents
from app.actors.review import commit_review_suggestion
from app.actors.webhook import handle_paperless_webhook
from app.events import types
from app.events.publish import publish_pipeline_event
from app.jobs.actor_execution import (
    list_stale_running_actor_executions,
    mark_stale_actor_execution_recovered,
)
from app.jobs.commands import (
    list_pending_embedding_build_commands,
    list_pending_poll_reconciliation_commands,
    list_pending_reindex_commands,
    mark_command_status,
)
from app.jobs.embedding_gate import ensure_embedding_index_ready
from app.jobs.pipeline_runs import (
    list_cancel_requested_pipeline_run_ids,
    list_due_retrying_document_pipeline_run_ids,
    list_embedding_blocked_pipeline_run_ids,
    list_pending_document_pipeline_run_ids,
    mark_pipeline_run_cancelled,
    mark_pipeline_run_pending,
    mark_pipeline_run_status,
)
from app.jobs.review_commit import (
    list_review_suggestions_ready_to_commit,
    mark_review_commit_status,
)
from app.jobs.webhook_delivery import (
    list_embedding_blocked_webhook_delivery_ids,
    list_queued_webhook_delivery_ids,
    mark_webhook_delivery_status,
)

log = structlog.get_logger(__name__)


def enqueue_embedding_build_command(command_id: int, limit: int | None = None) -> None:
    """Enqueue an admin-requested embedding build command."""
    _enqueue_command_actor(command_id, build_initial_embedding_index, limit)


def enqueue_poll_reconciliation_command(command_id: int, limit: int | None = None) -> None:
    """Enqueue an admin-requested polling reconciliation command."""
    _enqueue_command_actor(command_id, reconcile_inbox_documents, limit)


def enqueue_reindex_command(command_id: int, limit: int | None = None) -> None:
    """Enqueue an admin-requested reindex using the embedding rebuild actor.

    This is the first safe reindex control step: Laravel closes the embedding
    readiness gate before creating the command, and recovery bridges the command
    to the existing PostgreSQL/pgvector embedding rebuild actor.
    """
    _enqueue_command_actor(command_id, build_initial_embedding_index, limit)


def _enqueue_command_actor(command_id: int, actor, limit: int | None = None) -> None:
    """Mark a durable command queued and restore pending if broker send fails."""
    mark_command_status(command_id, "queued")
    try:
        send = getattr(actor, "send", None)
        if send is not None:
            send(limit)
            return

        actor(limit)
    except Exception as exc:
        mark_command_status(command_id, "pending", f"enqueue_failed:{type(exc).__name__}")
        raise


def enqueue_review_commit(review_suggestion_id: int) -> None:
    """Enqueue one accepted review suggestion for Paperless commit."""
    mark_review_commit_status(review_suggestion_id, "queued")
    send = getattr(commit_review_suggestion, "send", None)
    if send is not None:
        send(review_suggestion_id)
        return

    commit_review_suggestion(review_suggestion_id)


def enqueue_document_pipeline_run(pipeline_run_id: int) -> None:
    """Enqueue one pending document pipeline run for Dramatiq processing."""
    mark_pipeline_run_status(
        pipeline_run_id,
        status="queued",
        phase="document_actor",
        message="Document actor queued.",
    )
    try:
        send = getattr(handle_document_pipeline, "send", None)
        if send is not None:
            send(pipeline_run_id)
            return

        handle_document_pipeline(pipeline_run_id)
    except Exception as exc:
        mark_pipeline_run_status(
            pipeline_run_id,
            status="pending",
            phase="queued",
            message="Document actor enqueue failed; recovery will retry.",
            error_type="enqueue_failed",
            error=type(exc).__name__,
        )
        raise


def enqueue_webhook_delivery(webhook_delivery_id: int) -> None:
    """Enqueue one persisted webhook delivery for Dramatiq processing.

    In production `handle_paperless_webhook` is a Dramatiq actor and exposes
    `.send(...)`. In local/test environments without Dramatiq installed the
    fallback is the plain implementation function, so call it directly.
    """
    send = getattr(handle_paperless_webhook, "send", None)
    if send is not None:
        send(webhook_delivery_id)
        return

    handle_paperless_webhook(webhook_delivery_id)


def recover_stale_actor_executions(
    *, stale_after_seconds: int = 900, limit: int = 100
) -> tuple[int, int]:
    """Recover actor executions left running by worker/container crashes.

    Document pipeline actors can be safely requeued through the durable pipeline
    run id. Other stale actors are marked retrying for visibility and are picked
    up by their source-of-truth scans when possible (for example queued webhook
    deliveries and review commits below).
    """
    recovered = 0
    requeued = 0
    for execution in list_stale_running_actor_executions(
        stale_after_seconds=stale_after_seconds,
        limit=limit,
    ):
        mark_stale_actor_execution_recovered(execution.id)
        recovered += 1
        publish_pipeline_event(
            types.ACTOR_RECOVERED_STALE,
            pipeline_run_id=execution.pipeline_run_id,
            paperless_document_id=execution.paperless_document_id,
            level="warning",
            message="Stale actor execution recovered after worker restart.",
            payload={
                "actor_execution_id": execution.id,
                "actor_name": execution.actor_name,
                "retry_mode": "recovery",
            },
        )

        if execution.actor_name == "handle_document_pipeline" and execution.pipeline_run_id:
            enqueue_document_pipeline_run(execution.pipeline_run_id)
            requeued += 1

    return recovered, requeued


def finalize_cancel_requested_runs(limit: int = 100) -> int:
    """Finalize admin cancellation requests that are safe for recovery to close."""
    pipeline_run_ids = list_cancel_requested_pipeline_run_ids(limit=limit)
    for pipeline_run_id in pipeline_run_ids:
        mark_pipeline_run_cancelled(pipeline_run_id)
        publish_pipeline_event(
            types.PIPELINE_CANCELLED,
            pipeline_run_id=pipeline_run_id,
            level="warning",
            message="Pipeline run cancelled by admin request.",
        )

    return len(pipeline_run_ids)


def release_embedding_blocked_runs(limit: int = 100) -> int:
    """Move runs blocked by the embedding gate back to pending when safe."""
    if not ensure_embedding_index_ready():
        return 0

    pipeline_run_ids = list_embedding_blocked_pipeline_run_ids(limit=limit)
    for pipeline_run_id in pipeline_run_ids:
        mark_pipeline_run_pending(pipeline_run_id)
        publish_pipeline_event(
            types.PIPELINE_UNBLOCKED_EMBEDDING_READY,
            pipeline_run_id=pipeline_run_id,
            message="Pipeline run released because the embedding index is complete.",
        )

    return len(pipeline_run_ids)


def release_embedding_blocked_webhooks(limit: int = 100) -> int:
    """Move embedding-refresh webhook deliveries back to queued when safe."""
    if not ensure_embedding_index_ready():
        return 0

    webhook_delivery_ids = list_embedding_blocked_webhook_delivery_ids(limit=limit)
    for webhook_delivery_id in webhook_delivery_ids:
        mark_webhook_delivery_status(webhook_delivery_id, "queued", None)
        publish_pipeline_event(
            types.PIPELINE_UNBLOCKED_EMBEDDING_READY,
            webhook_delivery_id=webhook_delivery_id,
            message="Webhook delivery released because the embedding index is complete.",
        )

    return len(webhook_delivery_ids)


def run_recovery_scan(limit: int = 100) -> None:
    """Scan durable state and requeue safe stuck work."""
    recovered_actors, recovered_actor_requeues = recover_stale_actor_executions(limit=limit)
    cancelled_runs = finalize_cancel_requested_runs(limit=limit)

    webhooks_released = release_embedding_blocked_webhooks(limit=limit)
    webhook_delivery_ids = list_queued_webhook_delivery_ids(limit=limit)
    for webhook_delivery_id in webhook_delivery_ids:
        enqueue_webhook_delivery(webhook_delivery_id)

    pipeline_runs_requeued = release_embedding_blocked_runs(limit=limit)
    pending_pipeline_run_ids = list_pending_document_pipeline_run_ids(limit=limit)
    for pipeline_run_id in pending_pipeline_run_ids:
        enqueue_document_pipeline_run(pipeline_run_id)

    retrying_pipeline_run_ids = list_due_retrying_document_pipeline_run_ids(limit=limit)
    for pipeline_run_id in retrying_pipeline_run_ids:
        enqueue_document_pipeline_run(pipeline_run_id)

    embedding_build_commands = list_pending_embedding_build_commands(limit=limit)
    for command in embedding_build_commands:
        limit_value = command.payload.get("limit")
        enqueue_embedding_build_command(
            command.id,
            limit=int(limit_value) if isinstance(limit_value, int) and limit_value > 0 else None,
        )

    poll_reconciliation_commands = list_pending_poll_reconciliation_commands(limit=limit)
    for command in poll_reconciliation_commands:
        limit_value = command.payload.get("limit")
        enqueue_poll_reconciliation_command(
            command.id,
            limit=int(limit_value) if isinstance(limit_value, int) and limit_value > 0 else None,
        )

    reindex_commands = list_pending_reindex_commands(limit=limit)
    for command in reindex_commands:
        limit_value = command.payload.get("limit")
        enqueue_reindex_command(
            command.id,
            limit=int(limit_value) if isinstance(limit_value, int) and limit_value > 0 else None,
        )

    review_suggestion_ids = list_review_suggestions_ready_to_commit(limit=limit)
    for review_suggestion_id in review_suggestion_ids:
        enqueue_review_commit(review_suggestion_id)

    log.info(
        "recovery scan completed",
        actor_executions_recovered=recovered_actors,
        actor_executions_requeued=recovered_actor_requeues,
        pipeline_runs_cancelled=cancelled_runs,
        pipeline_runs_requeued=pipeline_runs_requeued
        + len(pending_pipeline_run_ids)
        + len(retrying_pipeline_run_ids)
        + recovered_actor_requeues,
        webhook_deliveries_released=webhooks_released,
        webhook_deliveries_requeued=len(webhook_delivery_ids),
        embedding_build_commands_requeued=len(embedding_build_commands),
        poll_reconciliation_commands_requeued=len(poll_reconciliation_commands),
        reindex_commands_requeued=len(reindex_commands),
        review_commits_requeued=len(review_suggestion_ids),
    )
