"""Review and commit actors for accepted suggestions."""

from __future__ import annotations

import asyncio
import time

import structlog

from app.actors import LARAVEL_DATABASE_QUEUE
from app.clients.paperless import PaperlessClient
from app.events import types
from app.events.publish import publish_pipeline_event
from app.execution_lifecycle import (
    ExecutionLifecycle,
    finish_actor_execution,
    start_actor_execution,
    update_actor_execution_progress,
)
from app.jobs.progress import ProgressSnapshot
from app.jobs.review_commit import (
    commit_review_suggestion_to_paperless,
    load_review_commit,
    mark_review_commit_status,
)

log = structlog.get_logger(__name__)


def run_async(coroutine):
    return asyncio.run(coroutine)


async def _commit_record(record):
    paperless = PaperlessClient()
    try:
        return await commit_review_suggestion_to_paperless(record, paperless)
    finally:
        await paperless.aclose()


commit_record = _commit_record


def _commit_review_suggestion_impl(
    review_suggestion_id: int, command_id: int | None = None
) -> None:
    started = time.monotonic()
    actor_name = "commit_review_suggestion"
    actor_execution = start_actor_execution(
        actor_name=actor_name,
        command_id=command_id,
        queue_name=LARAVEL_DATABASE_QUEUE,
    )
    log.info(
        "review commit actor started",
        event_type=types.ACTOR_STARTED,
        actor_name=actor_name,
        review_suggestion_id=review_suggestion_id,
        command_id=command_id,
        queue_name=LARAVEL_DATABASE_QUEUE,
    )

    if actor_execution.id is not None:
        update_actor_execution_progress(
            actor_execution.id,
            ProgressSnapshot(
                total=3,
                done=0,
                phase="review_commit_load",
                message="Review commit actor accepted the request.",
            ),
            current_item=f"review_suggestion:{review_suggestion_id}",
        )

    try:
        record = load_review_commit(review_suggestion_id)
        if record is None:
            publish_pipeline_event(
                types.REVIEW_COMMIT_SKIPPED,
                message="Accepted review suggestion was not found for commit.",
                payload={"review_suggestion_id": review_suggestion_id},
            )
            finish_actor_execution(
                actor_execution,
                status="failed_permanent",
                error_type="review_suggestion_not_found",
            )
            return

        mark_review_commit_status(review_suggestion_id, "running")
        if actor_execution.id is not None:
            update_actor_execution_progress(
                actor_execution.id,
                ProgressSnapshot(
                    total=3,
                    done=1,
                    phase="review_commit_paperless",
                    message="Committing accepted review suggestion to Paperless.",
                ),
                current_item=f"paperless_document:{record.paperless_document_id}",
            )
        fields = run_async(commit_record(record))
        mark_review_commit_status(review_suggestion_id, "committed")
        if actor_execution.id is not None:
            update_actor_execution_progress(
                actor_execution.id,
                ProgressSnapshot(
                    total=3,
                    done=3,
                    phase="review_commit_finished",
                    message="Accepted review suggestion committed to Paperless.",
                ),
                current_item=f"paperless_document:{record.paperless_document_id}",
            )
        publish_pipeline_event(
            types.REVIEW_COMMIT_SUCCEEDED,
            paperless_document_id=record.paperless_document_id,
            message="Accepted review suggestion committed to Paperless.",
            payload={
                "command_id": command_id,
                "review_suggestion_id": review_suggestion_id,
                "patched_fields": sorted(fields.keys()),
            },
        )
        finish_actor_execution(actor_execution, status="succeeded")
    except Exception as exc:
        disposition = ExecutionLifecycle(actor_execution).fail(exc)
        if disposition.retrying:
            mark_review_commit_status(
                review_suggestion_id, "retrying", disposition.retry_class.value
            )
        else:
            mark_review_commit_status(review_suggestion_id, "failed", disposition.retry_class.value)
        raise

    log.info(
        "review commit actor succeeded",
        event_type=types.ACTOR_SUCCEEDED,
        actor_name=actor_name,
        review_suggestion_id=review_suggestion_id,
        queue_name=LARAVEL_DATABASE_QUEUE,
        duration_ms=int((time.monotonic() - started) * 1000),
    )
