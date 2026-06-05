"""Maintenance actors for polling reconciliation, recovery and reindex."""

from __future__ import annotations

import asyncio
import time

import structlog

from app.absurd_queue import queue_backend, queue_name
from app.clients.paperless import PaperlessClient
from app.config import settings
from app.events import types
from app.events.publish import publish_pipeline_event
from app.jobs.actor_execution import (
    finish_actor_execution,
    schedule_actor_execution_retry,
    start_actor_execution,
)
from app.jobs.pipeline_start import start_or_attach_document_pipeline
from app.jobs.progress import ProgressSnapshot, update_actor_execution_progress
from app.jobs.retry import classify_exception, retry_backoff_seconds, should_retry

log = structlog.get_logger(__name__)


async def _fetch_inbox_documents() -> list[object]:
    paperless = PaperlessClient()
    try:
        return await paperless.list_inbox_documents(settings.paperless_inbox_tag_id)
    finally:
        await paperless.aclose()


def _modified_value(document: object) -> str | None:
    modified = getattr(document, "modified", None)
    return None if modified is None else str(modified)


def _reconcile_inbox_documents_impl(limit: int | None = None) -> None:
    """Poll Paperless inbox as reconciliation and use the shared pipeline start."""
    started = time.monotonic()
    actor_name = "reconcile_inbox_documents"
    actor_execution = start_actor_execution(actor_name=actor_name, queue_name=queue_name("io"))
    log.info(
        "poll reconciliation actor started",
        event_type=types.ACTOR_STARTED,
        actor_name=actor_name,
        queue_name=queue_name("io"),
        limit=limit,
    )

    try:
        if settings.paperless_inbox_tag_id <= 0:
            message = (
                "Polling reconciliation skipped because PAPERLESS_INBOX_TAG_ID is not configured."
            )
            publish_pipeline_event("poll.reconciliation.skipped", level="warning", message=message)
            finish_actor_execution(
                actor_execution,
                status="skipped",
                error_type="inbox_tag_not_configured",
                error_message=message,
            )
            return

        documents = asyncio.run(_fetch_inbox_documents())
        if limit is not None:
            documents = documents[:limit]

        total = len(documents)
        for index, document in enumerate(documents, 1):
            document_id = int(document.id)
            start_or_attach_document_pipeline(
                trigger_source="poll",
                paperless_document_id=document_id,
                paperless_modified=_modified_value(document),
            )
            if actor_execution.id is not None:
                update_actor_execution_progress(
                    actor_execution.id,
                    ProgressSnapshot(
                        total=total,
                        done=index,
                        phase="poll_reconciliation",
                        message="Polling reconciliation queued document pipeline starts.",
                    ),
                    current_item=f"paperless_document:{document_id}",
                )

        publish_pipeline_event(
            "poll.reconciliation.completed",
            message="Polling reconciliation completed.",
            payload={"documents_seen": total},
        )
        finish_actor_execution(actor_execution, status="succeeded")
    except Exception as exc:
        retry_class = classify_exception(exc)
        attempt = 1
        max_attempts = 5
        if should_retry(retry_class, attempt=attempt, max_attempts=max_attempts):
            backoff_seconds = retry_backoff_seconds(attempt)
            schedule_actor_execution_retry(
                actor_execution,
                retry_class=retry_class.value,
                retry_reason=type(exc).__name__,
                backoff_seconds=backoff_seconds,
                error_message=str(exc)[:1000],
            )
            publish_pipeline_event(
                types.ACTOR_RETRY_SCHEDULED,
                level="warning",
                message="Polling reconciliation actor retry scheduled.",
                payload={
                    "actor_name": actor_name,
                    "retry_class": retry_class.value,
                    "retry_reason": type(exc).__name__,
                    "backoff_seconds": backoff_seconds,
                },
            )
            raise

        finish_actor_execution(
            actor_execution,
            status="failed",
            error_type=retry_class.value,
            error_message=str(exc)[:1000],
        )
        raise

    log.info(
        "poll reconciliation actor succeeded",
        event_type=types.ACTOR_SUCCEEDED,
        actor_name=actor_name,
        queue_name=queue_name("io"),
        duration_ms=int((time.monotonic() - started) * 1000),
    )


if queue_backend is not None:
    reconcile_inbox_documents = queue_backend.actor(queue_name=queue_name("io"))(
        _reconcile_inbox_documents_impl
    )
else:  # pragma: no cover - lets local imports work before deps are installed
    reconcile_inbox_documents = _reconcile_inbox_documents_impl
