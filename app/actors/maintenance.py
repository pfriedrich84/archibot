"""Maintenance actors for polling reconciliation, recovery and reindex."""

from __future__ import annotations

import asyncio
import time

import structlog

from app.absurd_queue import queue_backend, queue_name
from app.ai_provider.factory import create_ai_provider
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
from app.jobs.review_suggestions import classified_document_ids
from app.pipeline.ocr_correction import batch_correct_documents, effective_ocr_mode

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


def _reconcile_inbox_documents_impl(limit: int | None = None, *, force: bool = False) -> None:
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
        force=force,
    )

    if actor_execution.id is not None:
        update_actor_execution_progress(
            actor_execution.id,
            ProgressSnapshot(
                total=0,
                done=0,
                phase="poll_reconciliation_prepare",
                message="Polling reconciliation actor accepted the request.",
            ),
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
        marked_document_ids = (
            set()
            if force
            else classified_document_ids([int(document.id) for document in documents])
        )
        started_count = 0
        coalesced_count = 0
        skipped_count = 0
        if actor_execution.id is not None:
            update_actor_execution_progress(
                actor_execution.id,
                ProgressSnapshot(
                    total=total,
                    done=0,
                    phase="poll_reconciliation",
                    message="Polling reconciliation fetched inbox documents.",
                ),
            )
        for index, document in enumerate(documents, 1):
            document_id = int(document.id)
            if document_id in marked_document_ids:
                skipped_count += 1
                progress_message = "Already classified Inbox Document skipped."
                publish_pipeline_event(
                    types.POLL_DOCUMENT_SKIPPED_ALREADY_CLASSIFIED,
                    paperless_document_id=document_id,
                    message=progress_message,
                    payload={"marker": "review_suggestion"},
                )
            else:
                force_options = (
                    {
                        "reprocess_requested": True,
                        "reprocess_reason": "forced_poll_reconciliation",
                        "reprocess_mode": "poll_force",
                        "force_new_run": True,
                    }
                    if force
                    else {}
                )
                result = start_or_attach_document_pipeline(
                    trigger_source="poll",
                    paperless_document_id=document_id,
                    paperless_modified=_modified_value(document),
                    **force_options,
                )
                if result.created:
                    started_count += 1
                    progress_message = "Polling reconciliation queued a document pipeline start."
                else:
                    coalesced_count += 1
                    progress_message = "Polling reconciliation coalesced with an existing run."
            if actor_execution.id is not None:
                update_actor_execution_progress(
                    actor_execution.id,
                    ProgressSnapshot(
                        total=total,
                        done=index,
                        skipped=skipped_count,
                        phase="poll_reconciliation",
                        message=progress_message,
                    ),
                    current_item=f"paperless_document:{document_id}",
                )

        publish_pipeline_event(
            "poll.reconciliation.completed",
            message="Polling reconciliation completed.",
            payload={
                "documents_seen": total,
                "pipelines_started": started_count,
                "pipelines_coalesced": coalesced_count,
                "documents_skipped_already_classified": skipped_count,
                "force": force,
            },
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


def _reindex_ocr_documents_impl(
    *, command_id: int | None = None, limit: int | None = None, force: bool = False
) -> None:
    """Run OCR reindex through the durable maintenance actor path."""
    started = time.monotonic()
    actor_name = "reindex_ocr"
    actor_execution = start_actor_execution(
        actor_name=actor_name, queue_name=queue_name("blocking")
    )
    mode = effective_ocr_mode()
    log.info(
        "ocr reindex actor started",
        event_type=types.ACTOR_STARTED,
        actor_name=actor_name,
        queue_name=queue_name("blocking"),
        command_id=command_id,
        limit=limit,
        force=force,
        mode=mode,
    )

    if actor_execution.id is not None:
        update_actor_execution_progress(
            actor_execution.id,
            ProgressSnapshot(
                total=0,
                done=0,
                phase="ocr_reindex_prepare",
                message="OCR reindex actor accepted the request.",
            ),
        )

    try:
        if mode == "off":
            message = "OCR reindex skipped because OCR_MODE is off."
            publish_pipeline_event(
                "ocr.reindex.skipped",
                command_id=command_id,
                level="warning",
                message=message,
                payload={"mode": mode, "force": force, "limit": limit},
            )
            finish_actor_execution(
                actor_execution,
                status="skipped",
                error_type="ocr_mode_off",
                error_message=message,
            )
            return

        paperless = PaperlessClient()
        provider = create_ai_provider()
        try:
            corrected = asyncio.run(
                batch_correct_documents(paperless, provider, limit=limit, force=force)
            )
        finally:
            asyncio.run(paperless.aclose())
            asyncio.run(provider.aclose())

        if actor_execution.id is not None:
            update_actor_execution_progress(
                actor_execution.id,
                ProgressSnapshot(
                    total=corrected,
                    done=corrected,
                    phase="ocr_reindex_finished",
                    message="OCR reindex completed.",
                ),
            )

        publish_pipeline_event(
            "ocr.reindex.completed",
            command_id=command_id,
            message="OCR reindex completed.",
            payload={"corrected": corrected, "mode": mode, "force": force, "limit": limit},
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
                command_id=command_id,
                level="warning",
                message="OCR reindex actor retry scheduled.",
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
        "ocr reindex actor succeeded",
        event_type=types.ACTOR_SUCCEEDED,
        actor_name=actor_name,
        queue_name=queue_name("blocking"),
        duration_ms=int((time.monotonic() - started) * 1000),
    )


if queue_backend is not None:
    reconcile_inbox_documents = queue_backend.actor(queue_name=queue_name("io"))(
        _reconcile_inbox_documents_impl
    )
    reindex_ocr_documents = queue_backend.actor(queue_name=queue_name("blocking"))(
        _reindex_ocr_documents_impl
    )
else:  # pragma: no cover - lets local imports work before deps are installed
    reconcile_inbox_documents = _reconcile_inbox_documents_impl
    reindex_ocr_documents = _reindex_ocr_documents_impl
