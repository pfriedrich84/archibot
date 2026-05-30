"""Embedding actors and initial embedding-index build actors."""

from __future__ import annotations

import asyncio
import time

import structlog

from app.ai_provider.factory import create_ai_provider
from app.clients.paperless import PaperlessClient
from app.config import settings
from app.dramatiq_broker import dramatiq, queue_name
from app.events import types
from app.events.publish import publish_pipeline_event
from app.jobs.actor_execution import (
    finish_actor_execution,
    schedule_actor_execution_retry,
    start_actor_execution,
)
from app.jobs.document_embeddings import (
    DocumentEmbeddingInput,
    document_embedding_text,
    is_trusted_document,
    store_document_embedding,
)
from app.jobs.embedding_index import (
    finish_embedding_index_build,
    start_embedding_index_build,
    update_embedding_index_progress,
)
from app.jobs.progress import ProgressSnapshot, update_actor_execution_progress
from app.jobs.retry import classify_exception, retry_backoff_seconds, should_retry

log = structlog.get_logger(__name__)


async def _build_pgvector_embeddings(
    build_id: int, limit: int | None, actor_execution_id: int | None
) -> tuple[int, int, int]:
    paperless = PaperlessClient()
    ollama = create_ai_provider()
    embedded_count = 0
    failed_count = 0
    try:
        fetched_documents = await paperless.list_all_documents(limit=limit)
        trusted_documents = [
            document for document in fetched_documents if is_trusted_document(document)
        ]
        documents_with_text = [
            (document, text)
            for document in trusted_documents
            if (text := document_embedding_text(document.title, document.content))
        ]
        skipped_empty_text_count = len(trusted_documents) - len(documents_with_text)
        if skipped_empty_text_count:
            log.info(
                "skipping trusted documents without embedding text",
                skipped_empty_text_count=skipped_empty_text_count,
            )
        total = len(documents_with_text)
        update_embedding_index_progress(
            build_id,
            document_count=total,
            embedded_count=embedded_count,
            failed_count=failed_count,
        )
        for index, (document, text) in enumerate(documents_with_text, 1):
            try:
                embedding = await ollama.embed(text)
                store_document_embedding(
                    DocumentEmbeddingInput(
                        paperless_document_id=document.id,
                        title=document.title,
                        content=document.content,
                        embedding_model=ollama.embed_model,
                        embedding=embedding,
                        created_date=document.created_date,
                        metadata={
                            "correspondent": document.correspondent,
                            "document_type": document.document_type,
                            "storage_path": document.storage_path,
                            "tags": document.tags,
                            "modified": document.modified,
                        },
                        correspondent_id=document.correspondent,
                        document_type_id=document.document_type,
                        storage_path_id=document.storage_path,
                        tags=document.tags,
                        paperless_modified=str(document.modified)
                        if document.modified is not None
                        else None,
                        trusted_for_context=True,
                    )
                )
                embedded_count += 1
            except Exception as exc:
                failed_count += 1
                log.warning(
                    "failed to embed document",
                    paperless_document_id=document.id,
                    error_type=type(exc).__name__,
                )

            update_embedding_index_progress(
                build_id,
                document_count=total,
                embedded_count=embedded_count,
                failed_count=failed_count,
            )
            if actor_execution_id is not None:
                update_actor_execution_progress(
                    actor_execution_id,
                    ProgressSnapshot(
                        total=total,
                        done=index,
                        failed=failed_count,
                        phase="embedding_index",
                        message="Embedding index build in progress.",
                    ),
                    current_item=f"paperless_document:{document.id}",
                )
    finally:
        await ollama.aclose()
        await paperless.aclose()

    return total, embedded_count, failed_count


def _build_initial_embedding_index_impl(limit: int | None = None) -> None:
    """Build the initial PostgreSQL/pgvector document embedding index."""
    started = time.monotonic()
    actor_name = "build_initial_embedding_index"
    actor_execution = start_actor_execution(
        actor_name=actor_name,
        queue_name=queue_name("embedding"),
    )
    build = start_embedding_index_build(
        embedding_model=settings.ollama_embed_model,
        dimensions=None,
        content_scope="trusted_documents_without_inbox_tag",
        document_count=limit or 0,
    )
    if build.already_running:
        message = "Embedding index build is already running."
        publish_pipeline_event(
            types.EMBEDDING_INDEX_BUILD_STARTED,
            level="warning",
            message=message,
            payload={"embedding_index_state_id": build.id, "limit": limit, "already_running": True},
        )
        finish_actor_execution(
            actor_execution,
            status="skipped",
            error_type="embedding_index_already_building",
            error_message=message,
        )
        return

    log.info(
        "embedding index actor started",
        event_type=types.ACTOR_STARTED,
        actor_name=actor_name,
        queue_name=queue_name("embedding"),
        embedding_index_state_id=build.id,
        limit=limit,
    )

    try:
        snapshot = ProgressSnapshot(
            total=limit or 0,
            done=0,
            failed=0,
            phase="embedding_index_prepare",
            message="Embedding index build actor accepted the request.",
        )
        update_embedding_index_progress(
            build.id,
            document_count=snapshot.total,
            embedded_count=snapshot.done,
            failed_count=snapshot.failed,
        )
        if actor_execution.id is not None:
            update_actor_execution_progress(
                actor_execution.id, snapshot, current_item=f"embedding_index:{build.id}"
            )
        publish_pipeline_event(
            types.EMBEDDING_INDEX_BUILD_STARTED,
            message="Embedding index build actor accepted the request.",
            payload={"embedding_index_state_id": build.id, "limit": limit},
        )

        total, embedded_count, failed_count = asyncio.run(
            _build_pgvector_embeddings(build.id, limit, actor_execution.id)
        )
        final_status = "complete" if failed_count == 0 else "failed"
        finish_embedding_index_build(
            build.id,
            status=final_status,
            error=None if failed_count == 0 else f"{failed_count} document embeddings failed",
        )
        publish_pipeline_event(
            types.EMBEDDING_INDEX_BUILD_COMPLETED
            if failed_count == 0
            else types.EMBEDDING_INDEX_BUILD_FAILED,
            message="Embedding index build completed."
            if failed_count == 0
            else "Embedding index build completed with failures.",
            payload={
                "embedding_index_state_id": build.id,
                "document_count": total,
                "embedded_count": embedded_count,
                "failed_count": failed_count,
            },
        )
        finish_actor_execution(
            actor_execution,
            status="succeeded" if failed_count == 0 else "failed",
            error_type=None if failed_count == 0 else "embedding_documents_failed",
            error_message=None
            if failed_count == 0
            else f"{failed_count} document embeddings failed",
        )
    except Exception as exc:
        retry_class = classify_exception(exc)
        attempt = 1
        max_attempts = 5
        if should_retry(retry_class, attempt=attempt, max_attempts=max_attempts):
            backoff_seconds = retry_backoff_seconds(attempt)
            finish_embedding_index_build(
                build.id,
                status="failed",
                error=f"Retry scheduled after {type(exc).__name__}.",
            )
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
                message="Embedding index actor retry scheduled.",
                payload={
                    "actor_name": actor_name,
                    "embedding_index_state_id": build.id,
                    "retry_class": retry_class.value,
                    "retry_reason": type(exc).__name__,
                    "backoff_seconds": backoff_seconds,
                },
            )
            raise

        finish_embedding_index_build(build.id, status="failed", error=str(exc)[:1000])
        finish_actor_execution(
            actor_execution,
            status="failed",
            error_type=retry_class.value,
            error_message=str(exc)[:1000],
        )
        raise

    log.info(
        "embedding index actor completed",
        event_type=types.ACTOR_SUCCEEDED,
        actor_name=actor_name,
        queue_name=queue_name("embedding"),
        embedding_index_state_id=build.id,
        duration_ms=int((time.monotonic() - started) * 1000),
    )


if dramatiq is not None:
    build_initial_embedding_index = dramatiq.actor(queue_name=queue_name("embedding"))(
        _build_initial_embedding_index_impl
    )
else:  # pragma: no cover - lets local imports work before deps are installed
    build_initial_embedding_index = _build_initial_embedding_index_impl
