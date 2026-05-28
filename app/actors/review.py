"""Review and commit actors for accepted suggestions."""

from __future__ import annotations

import asyncio
import time

import structlog

from app.clients.paperless import PaperlessClient
from app.dramatiq_broker import dramatiq, queue_name
from app.events import types
from app.events.publish import publish_pipeline_event
from app.jobs.actor_execution import (
    finish_actor_execution,
    schedule_actor_execution_retry,
    start_actor_execution,
)
from app.jobs.commands import mark_command_status
from app.jobs.retry import classify_exception, retry_backoff_seconds, should_retry
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


def _commit_review_suggestion_impl(review_suggestion_id: int, command_id: int | None = None) -> None:
    started = time.monotonic()
    actor_name = "commit_review_suggestion"
    actor_execution = start_actor_execution(actor_name=actor_name, queue_name=queue_name("io"))
    log.info(
        "review commit actor started",
        event_type=types.ACTOR_STARTED,
        actor_name=actor_name,
        review_suggestion_id=review_suggestion_id,
        command_id=command_id,
        queue_name=queue_name("io"),
    )

    if command_id is not None:
        mark_command_status(command_id, "running")

    try:
        record = load_review_commit(review_suggestion_id)
        if record is None:
            publish_pipeline_event(
                types.REVIEW_COMMIT_SKIPPED,
                message="Accepted review suggestion was not found for commit.",
                payload={"review_suggestion_id": review_suggestion_id},
            )
            finish_actor_execution(
                actor_execution, status="skipped", error_type="review_suggestion_not_found"
            )
            if command_id is not None:
                mark_command_status(command_id, "failed_permanent", "review_suggestion_not_found")
            return

        mark_review_commit_status(review_suggestion_id, "running")
        fields = run_async(commit_record(record))
        mark_review_commit_status(review_suggestion_id, "committed")
        if command_id is not None:
            mark_command_status(command_id, "succeeded")
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
        retry_class = classify_exception(exc)
        attempt = 1
        max_attempts = 5
        if should_retry(retry_class, attempt=attempt, max_attempts=max_attempts):
            backoff_seconds = retry_backoff_seconds(attempt)
            mark_review_commit_status(review_suggestion_id, "retrying", retry_class.value)
            if command_id is not None:
                mark_command_status(command_id, "pending", retry_class.value)
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
                message="Review commit actor retry scheduled.",
                payload={
                    "actor_name": actor_name,
                    "review_suggestion_id": review_suggestion_id,
                    "command_id": command_id,
                    "retry_class": retry_class.value,
                    "retry_reason": type(exc).__name__,
                    "backoff_seconds": backoff_seconds,
                },
            )
            raise

        mark_review_commit_status(review_suggestion_id, "failed", retry_class.value)
        if command_id is not None:
            mark_command_status(command_id, "failed", retry_class.value)
        finish_actor_execution(
            actor_execution,
            status="failed",
            error_type=retry_class.value,
            error_message=str(exc)[:1000],
        )
        raise

    log.info(
        "review commit actor succeeded",
        event_type=types.ACTOR_SUCCEEDED,
        actor_name=actor_name,
        review_suggestion_id=review_suggestion_id,
        queue_name=queue_name("io"),
        duration_ms=int((time.monotonic() - started) * 1000),
    )


if dramatiq is not None:
    commit_review_suggestion = dramatiq.actor(queue_name=queue_name("io"))(
        _commit_review_suggestion_impl
    )
else:  # pragma: no cover - lets local imports work before deps are installed
    commit_review_suggestion = _commit_review_suggestion_impl
