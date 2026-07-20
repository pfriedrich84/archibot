"""Maintenance actors for polling reconciliation, recovery and reindex."""

from __future__ import annotations

import asyncio
import time
from datetime import datetime

import structlog

from app.actors import LARAVEL_DATABASE_QUEUE
from app.ai_provider.factory import create_ai_provider
from app.clients.paperless import PaperlessClient
from app.config import settings
from app.events import types
from app.events.publish import publish_pipeline_event
from app.execution_lifecycle import (
    ExecutionLifecycle,
    finish_actor_execution,
    start_actor_execution,
    update_actor_execution_progress,
)
from app.jobs.poll_candidates import persist_poll_candidate
from app.jobs.progress import ProgressSnapshot
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
    if modified is None:
        return None
    if isinstance(modified, datetime):
        return modified.isoformat()
    return str(modified)


def _reconcile_inbox_documents_impl(
    limit: int | None = None, *, force: bool = False, command_id: int | None = None
) -> None:
    """Poll Paperless inbox as reconciliation and use the shared pipeline start."""
    started = time.monotonic()
    actor_name = "reconcile_inbox_documents"
    actor_execution = start_actor_execution(
        actor_name=actor_name,
        command_id=command_id,
        queue_name=LARAVEL_DATABASE_QUEUE,
    )
    log.info(
        "poll reconciliation actor started",
        event_type=types.ACTOR_STARTED,
        actor_name=actor_name,
        queue_name=LARAVEL_DATABASE_QUEUE,
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
        if command_id is None:
            raise ValueError("Poll reconciliation requires a durable Laravel command id.")
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
        persisted_count = 0
        replayed_count = 0
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
            marked = document_id in marked_document_ids
            result = persist_poll_candidate(
                command_id=command_id,
                paperless_document_id=document_id,
                discovered_modified=_modified_value(document),
                marker_disposition=("already_classified" if marked else "unclassified"),
                force=force,
            )
            if result.created:
                persisted_count += 1
                progress_message = "Polling reconciliation persisted a Laravel start candidate."
            else:
                replayed_count += 1
                progress_message = "Polling reconciliation replay found an existing candidate."
            if marked:
                skipped_count += 1
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
                "candidates_persisted": persisted_count,
                "candidates_replayed": replayed_count,
                "documents_marked_already_classified": skipped_count,
                "force": force,
            },
        )
        finish_actor_execution(actor_execution, status="succeeded")
    except Exception as exc:
        ExecutionLifecycle(actor_execution).fail(exc)
        raise

    log.info(
        "poll reconciliation actor succeeded",
        event_type=types.ACTOR_SUCCEEDED,
        actor_name=actor_name,
        queue_name=LARAVEL_DATABASE_QUEUE,
        duration_ms=int((time.monotonic() - started) * 1000),
    )


def _reindex_ocr_documents_impl(
    *, command_id: int | None = None, limit: int | None = None, force: bool = False
) -> None:
    """Run OCR reindex through the durable maintenance actor path."""
    started = time.monotonic()
    actor_name = "reindex_ocr"
    actor_execution = start_actor_execution(
        actor_name=actor_name,
        command_id=command_id,
        queue_name=LARAVEL_DATABASE_QUEUE,
    )
    mode = effective_ocr_mode()
    log.info(
        "ocr reindex actor started",
        event_type=types.ACTOR_STARTED,
        actor_name=actor_name,
        queue_name=LARAVEL_DATABASE_QUEUE,
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
        ExecutionLifecycle(actor_execution).fail(exc)
        raise

    log.info(
        "ocr reindex actor succeeded",
        event_type=types.ACTOR_SUCCEEDED,
        actor_name=actor_name,
        queue_name=LARAVEL_DATABASE_QUEUE,
        duration_ms=int((time.monotonic() - started) * 1000),
    )
