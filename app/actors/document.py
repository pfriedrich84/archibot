"""Document processing actors for the event-driven pipeline."""

from __future__ import annotations

import asyncio
import time

import structlog

from app.clients.ollama import OllamaClient
from app.clients.paperless import PaperlessClient
from app.dramatiq_broker import dramatiq, queue_name
from app.events import types
from app.events.publish import publish_pipeline_event
from app.jobs.actor_execution import finish_actor_execution, start_actor_execution
from app.jobs.document_embeddings import (
    document_embedding_text,
    find_similar_with_precomputed_embedding,
)
from app.jobs.embedding_gate import ensure_embedding_index_ready
from app.jobs.pipeline_items import (
    finish_pipeline_item,
    progress_from_pipeline_items,
    start_pipeline_item,
)
from app.jobs.pipeline_runs import (
    load_document_pipeline_run,
    mark_pipeline_run_retrying,
    mark_pipeline_run_status,
)
from app.jobs.progress import (
    ProgressSnapshot,
    update_actor_execution_progress,
    update_pipeline_run_progress,
)
from app.jobs.retry import classify_exception, retry_backoff_seconds, should_retry
from app.jobs.review_suggestions import store_review_suggestion
from app.pipeline.classifier import classify

log = structlog.get_logger(__name__)


def run_async(coroutine):
    return asyncio.run(coroutine)


async def _fetch_paperless_document(paperless_document_id: int):
    paperless = PaperlessClient()
    try:
        return await paperless.get_document(paperless_document_id)
    finally:
        await paperless.aclose()


async def _classify_document(document):
    paperless = PaperlessClient()
    ollama = OllamaClient()
    try:
        correspondents = await paperless.list_correspondents()
        doctypes = await paperless.list_document_types()
        storage_paths = await paperless.list_storage_paths()
        tags = await paperless.list_tags()
        context_documents = []
        text = document_embedding_text(document.title, document.content)
        if text.strip():
            try:
                embedding = await ollama.embed(text)
                similar = await find_similar_with_precomputed_embedding(document, embedding, paperless)
                context_documents = [item.document for item in similar]
            except Exception as exc:
                log.warning(
                    "document context search failed",
                    paperless_document_id=document.id,
                    error_type=type(exc).__name__,
                )
        result, raw_response = await classify(
            document,
            context_documents,
            correspondents,
            doctypes,
            storage_paths,
            tags,
            ollama,
        )
        return result, raw_response, context_documents
    finally:
        await ollama.aclose()
        await paperless.aclose()


def _update_item_derived_progress(
    *,
    pipeline_run_id: int,
    actor_execution_id: int | None,
    phase: str,
    message: str,
    current_item: str | None = None,
) -> None:
    total, done, failed, skipped = progress_from_pipeline_items(pipeline_run_id)
    snapshot = ProgressSnapshot(
        total=total,
        done=done,
        failed=failed,
        skipped=skipped,
        phase=phase,
        message=message,
    )
    update_pipeline_run_progress(pipeline_run_id, snapshot)
    if actor_execution_id is not None:
        update_actor_execution_progress(actor_execution_id, snapshot, current_item=current_item)


def _handle_document_pipeline_impl(pipeline_run_id: int) -> None:
    """Handle one document pipeline run through durable event-driven steps.

    This actor does not call the legacy CLI/subprocess worker path. It performs
    the read-only Paperless fetch step, records durable item/progress state, and
    then blocks at the next migration boundary before classification/commit.
    """
    started = time.monotonic()
    actor_name = "handle_document_pipeline"
    actor_execution = start_actor_execution(
        actor_name=actor_name,
        pipeline_run_id=pipeline_run_id,
        queue_name=queue_name("io"),
    )
    log.info(
        "document actor started",
        event_type=types.ACTOR_STARTED,
        pipeline_run_id=pipeline_run_id,
        actor_name=actor_name,
        queue_name=queue_name("io"),
    )

    run = None
    try:
        run = load_document_pipeline_run(pipeline_run_id)
        if run is None:
            message = "Document pipeline run was not found."
            finish_actor_execution(
                actor_execution,
                status="failed",
                error_type="pipeline_run_not_found",
                error_message=message,
            )
            publish_pipeline_event(
                types.ACTOR_FAILED, pipeline_run_id=pipeline_run_id, level="error", message=message
            )
            return

        if not ensure_embedding_index_ready():
            message = "Document actor blocked because the embedding index is not ready."
            mark_pipeline_run_status(
                pipeline_run_id,
                status="blocked",
                phase="blocked",
                message="Waiting for embedding index to complete.",
                error_type="embedding_index_not_ready",
                error="Waiting for embedding index to complete.",
            )
            finish_actor_execution(
                actor_execution,
                status="blocked",
                error_type="embedding_index_not_ready",
                error_message=message,
            )
            publish_pipeline_event(
                types.PIPELINE_BLOCKED_EMBEDDING_NOT_READY,
                pipeline_run_id=pipeline_run_id,
                paperless_document_id=run.paperless_document_id,
                level="warning",
                message=message,
            )
            return

        mark_pipeline_run_status(
            pipeline_run_id,
            status="running",
            phase="paperless_fetch",
            message="Fetching document from Paperless.",
        )
        publish_pipeline_event(
            types.DOCUMENT_ACTOR_READY,
            pipeline_run_id=pipeline_run_id,
            paperless_document_id=run.paperless_document_id,
            message="Document actor accepted the pipeline run.",
        )

        fetch_item = start_pipeline_item(
            pipeline_run_id=pipeline_run_id,
            item_type="paperless_fetch",
            paperless_document_id=run.paperless_document_id,
        )
        document = run_async(_fetch_paperless_document(run.paperless_document_id))
        finish_pipeline_item(fetch_item.id, status="succeeded")
        _update_item_derived_progress(
            pipeline_run_id=pipeline_run_id,
            actor_execution_id=actor_execution.id,
            phase="paperless_fetch",
            message="Document fetched from Paperless.",
            current_item=f"paperless_document:{run.paperless_document_id}",
        )
        publish_pipeline_event(
            types.DOCUMENT_FETCHED,
            pipeline_run_id=pipeline_run_id,
            paperless_document_id=run.paperless_document_id,
            message="Document fetched from Paperless.",
            payload={
                "title_present": bool(getattr(document, "title", None)),
                "content_present": bool(getattr(document, "content", None)),
            },
        )

        classify_item = start_pipeline_item(
            pipeline_run_id=pipeline_run_id,
            item_type="classification",
            paperless_document_id=run.paperless_document_id,
        )
        result, raw_response, context_documents = run_async(_classify_document(document))
        finish_pipeline_item(classify_item.id, status="succeeded")
        _update_item_derived_progress(
            pipeline_run_id=pipeline_run_id,
            actor_execution_id=actor_execution.id,
            phase="classification",
            message="Document classified by LLM.",
            current_item=f"paperless_document:{run.paperless_document_id}",
        )
        publish_pipeline_event(
            types.DOCUMENT_CLASSIFIED,
            pipeline_run_id=pipeline_run_id,
            paperless_document_id=run.paperless_document_id,
            message="Document classified by LLM.",
            payload={
                "title": result.title,
                "date": result.date,
                "correspondent": result.correspondent,
                "document_type": result.document_type,
                "storage_path": result.storage_path,
                "tag_count": len(result.tags),
                "confidence": result.confidence,
                "raw_response_chars": len(raw_response),
            },
        )

        review_item = start_pipeline_item(
            pipeline_run_id=pipeline_run_id,
            item_type="review_suggestion",
            paperless_document_id=run.paperless_document_id,
        )
        suggestion = store_review_suggestion(
            paperless_document_id=run.paperless_document_id,
            document=document,
            result=result,
            raw_response=raw_response,
            context_documents=context_documents,
            pipeline_run_id=pipeline_run_id,
        )
        finish_pipeline_item(review_item.id, status="succeeded")
        _update_item_derived_progress(
            pipeline_run_id=pipeline_run_id,
            actor_execution_id=actor_execution.id,
            phase="review_suggestion",
            message="Review suggestion persisted for manual review.",
            current_item=f"review_suggestion:{suggestion.id}",
        )
        publish_pipeline_event(
            types.DOCUMENT_REVIEW_SUGGESTION_STORED,
            pipeline_run_id=pipeline_run_id,
            paperless_document_id=run.paperless_document_id,
            message="Review suggestion persisted for manual review.",
            payload={"review_suggestion_id": suggestion.id, "status": suggestion.status},
        )

        mark_pipeline_run_status(
            pipeline_run_id,
            status="succeeded",
            phase="review_suggestion",
            message="Document classification is ready for manual review.",
        )
        finish_actor_execution(actor_execution, status="succeeded")
    except Exception as exc:
        retry_class = classify_exception(exc)
        attempt = 1 if run is None else run.retry_count + 1
        max_attempts = 5 if run is None else run.max_retries
        if should_retry(retry_class, attempt=attempt, max_attempts=max_attempts):
            backoff_seconds = retry_backoff_seconds(attempt)
            message = f"Document actor retry scheduled in {backoff_seconds} seconds."
            mark_pipeline_run_retrying(
                pipeline_run_id,
                retry_class=retry_class.value,
                retry_reason=type(exc).__name__,
                backoff_seconds=backoff_seconds,
                phase="document_actor",
                message=message,
            )
            finish_actor_execution(
                actor_execution,
                status="retrying",
                error_type=retry_class.value,
                error_message=str(exc)[:1000],
            )
            publish_pipeline_event(
                types.ACTOR_RETRY_SCHEDULED,
                pipeline_run_id=pipeline_run_id,
                level="warning",
                message=message,
                payload={
                    "actor_name": actor_name,
                    "retry_class": retry_class.value,
                    "retry_reason": type(exc).__name__,
                    "backoff_seconds": backoff_seconds,
                },
            )
            return

        mark_pipeline_run_status(
            pipeline_run_id,
            status="failed",
            phase="document_actor",
            message="Document actor failed.",
            error_type=retry_class.value,
            error=str(exc)[:1000],
        )
        finish_actor_execution(
            actor_execution,
            status="failed",
            error_type=retry_class.value,
            error_message=str(exc)[:1000],
        )
        raise

    log.info(
        "document actor completed with review suggestion",
        event_type=types.ACTOR_SUCCEEDED,
        pipeline_run_id=pipeline_run_id,
        actor_name=actor_name,
        queue_name=queue_name("io"),
        duration_ms=int((time.monotonic() - started) * 1000),
    )


if dramatiq is not None:
    handle_document_pipeline = dramatiq.actor(queue_name=queue_name("io"))(
        _handle_document_pipeline_impl
    )
else:  # pragma: no cover - lets local imports work before deps are installed
    handle_document_pipeline = _handle_document_pipeline_impl
