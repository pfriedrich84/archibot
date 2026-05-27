"""Document processing actors for the event-driven pipeline."""

from __future__ import annotations

import asyncio
import time
from dataclasses import dataclass
from typing import Any

import structlog

from app.clients.ollama import OllamaClient
from app.clients.paperless import PaperlessClient
from app.config import settings
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
    PipelineItemRecord,
    finish_pipeline_item,
    progress_from_pipeline_items,
    start_or_resume_pipeline_item,
)
from app.jobs.pipeline_runs import (
    is_pipeline_run_cancel_requested,
    load_document_pipeline_run,
    mark_pipeline_run_cancelled,
    mark_pipeline_run_retrying,
    mark_pipeline_run_status,
)
from app.jobs.progress import (
    ProgressSnapshot,
    update_actor_execution_progress,
    update_pipeline_run_progress,
)
from app.jobs.retry import classify_exception, retry_backoff_seconds, should_retry
from app.jobs.review_suggestions import (
    mark_review_suggestion_auto_accepted,
    store_review_suggestion,
)
from app.models import ClassificationResult, PaperlessDocument, PaperlessEntity
from app.pipeline.classifier import classify
from app.pipeline.document_processing import maybe_run_judge
from app.pipeline.ocr_correction import (
    cache_ocr_correction,
    effective_ocr_mode,
    maybe_correct_ocr,
    should_run_ocr_for_document,
)

log = structlog.get_logger(__name__)


@dataclass(frozen=True)
class EntityCatalog:
    correspondents: list[PaperlessEntity]
    doctypes: list[PaperlessEntity]
    storage_paths: list[PaperlessEntity]
    tags: list[PaperlessEntity]


@dataclass(frozen=True)
class DocumentClassificationOutcome:
    document: PaperlessDocument
    result: ClassificationResult
    raw_response: str
    context_documents: list[Any]
    catalog: EntityCatalog
    judge_verdict: str | None = None
    judge_reasoning: str | None = None
    original_proposed_json: str | None = None
    ocr_corrected: bool = False
    ocr_corrections: int = 0
    context_count: int = 0


def run_async(coroutine):
    return asyncio.run(coroutine)


async def _fetch_paperless_document(paperless_document_id: int) -> PaperlessDocument:
    paperless = PaperlessClient()
    try:
        return await paperless.get_document(paperless_document_id)
    finally:
        await paperless.aclose()


async def _load_entity_catalog(paperless: PaperlessClient) -> EntityCatalog:
    return EntityCatalog(
        correspondents=await paperless.list_correspondents(),
        doctypes=await paperless.list_document_types(),
        storage_paths=await paperless.list_storage_paths(),
        tags=await paperless.list_tags(),
    )


async def _classify_document(document: PaperlessDocument) -> DocumentClassificationOutcome:
    paperless = PaperlessClient()
    ollama = OllamaClient()
    try:
        catalog = await _load_entity_catalog(paperless)
        processed_document = document
        ocr_corrected = False
        ocr_corrections = 0
        ocr_mode = effective_ocr_mode()
        eligible, reason = should_run_ocr_for_document(
            processed_document, available_tags=catalog.tags, require_tag_info=False
        )
        if eligible:
            try:
                corrected_text, ocr_corrections = await maybe_correct_ocr(
                    processed_document, ollama, paperless
                )
                if ocr_corrections > 0:
                    processed_document = processed_document.model_copy(
                        update={"content": corrected_text}
                    )
                    cache_ocr_correction(
                        processed_document.id, corrected_text, ocr_mode, ocr_corrections
                    )
                    ocr_corrected = True
            except Exception as exc:
                log.warning(
                    "document OCR correction failed",
                    paperless_document_id=document.id,
                    error_type=type(exc).__name__,
                )
        else:
            log.debug(
                "document OCR skipped",
                paperless_document_id=document.id,
                reason=reason,
            )

        context_documents: list[Any] = []
        text = document_embedding_text(processed_document.title, processed_document.content)
        if text.strip():
            try:
                embedding = await ollama.embed(text)
                similar = await find_similar_with_precomputed_embedding(
                    processed_document, embedding, paperless
                )
                context_documents = [item.document for item in similar]
            except Exception as exc:
                log.warning(
                    "document context search failed",
                    paperless_document_id=document.id,
                    error_type=type(exc).__name__,
                )

        initial_result, raw_response = await classify(
            processed_document,
            context_documents,
            catalog.correspondents,
            catalog.doctypes,
            catalog.storage_paths,
            catalog.tags,
            ollama,
        )
        judge = await maybe_run_judge(
            processed_document,
            initial_result,
            raw_response,
            context_documents,
            catalog.correspondents,
            catalog.doctypes,
            catalog.storage_paths,
            catalog.tags,
            ollama,
            cycle_id=None,
        )
        return DocumentClassificationOutcome(
            document=processed_document,
            result=judge.result,
            raw_response=raw_response,
            context_documents=context_documents,
            catalog=catalog,
            judge_verdict=judge.verdict,
            judge_reasoning=judge.reasoning,
            original_proposed_json=judge.original_proposed_json,
            ocr_corrected=ocr_corrected,
            ocr_corrections=ocr_corrections,
            context_count=len(context_documents),
        )
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


def start_pipeline_item(
    *, pipeline_run_id: int, item_type: str, paperless_document_id: int | None = None
) -> PipelineItemRecord:
    """Start/resume a document actor phase item by stable key.

    Kept as a module-level wrapper so tests can patch the actor seam without
    patching the lower-level durable item helper.
    """
    item_key = f"{item_type}:{paperless_document_id}" if paperless_document_id is not None else item_type
    return start_or_resume_pipeline_item(
        pipeline_run_id=pipeline_run_id,
        item_type=item_type,
        item_key=item_key,
        paperless_document_id=paperless_document_id,
    )


def _phase_item(
    *, pipeline_run_id: int, paperless_document_id: int, item_type: str
) -> PipelineItemRecord:
    return start_pipeline_item(
        pipeline_run_id=pipeline_run_id,
        item_type=item_type,
        paperless_document_id=paperless_document_id,
    )


def _record_completed_phase_item(
    *,
    pipeline_run_id: int,
    paperless_document_id: int,
    item_type: str,
    status: str = "succeeded",
    error: str | None = None,
) -> None:
    item = _phase_item(
        pipeline_run_id=pipeline_run_id,
        item_type=item_type,
        paperless_document_id=paperless_document_id,
    )
    finish_pipeline_item(item.id, status=status, error=error)


def _ensure_not_cancelled(pipeline_run_id: int, current_item: PipelineItemRecord | None = None) -> None:
    if not is_pipeline_run_cancel_requested(pipeline_run_id):
        return
    if current_item is not None:
        finish_pipeline_item(current_item.id, status="skipped", error="Pipeline run cancelled.")
    mark_pipeline_run_cancelled(pipeline_run_id)
    publish_pipeline_event(
        types.PIPELINE_CANCELLED,
        pipeline_run_id=pipeline_run_id,
        level="warning",
        message="Pipeline run cancelled by admin request.",
    )
    raise DocumentPipelineCancelled("Pipeline run cancelled by admin request.")


class DocumentPipelineCancelled(Exception):
    """Raised internally when cancellation is observed between phases."""


def _handle_document_pipeline_impl(pipeline_run_id: int) -> None:
    """Handle one document pipeline run through durable event-driven steps."""
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
    current_item: PipelineItemRecord | None = None
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

        _ensure_not_cancelled(pipeline_run_id)
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

        current_item = _phase_item(
            pipeline_run_id=pipeline_run_id,
            item_type="paperless_fetch",
            paperless_document_id=run.paperless_document_id,
        )
        document = run_async(_fetch_paperless_document(run.paperless_document_id))
        finish_pipeline_item(current_item.id, status="succeeded")
        current_item = None
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

        _ensure_not_cancelled(pipeline_run_id)
        current_item = _phase_item(
            pipeline_run_id=pipeline_run_id,
            item_type="classification",
            paperless_document_id=run.paperless_document_id,
        )
        outcome = run_async(_classify_document(document))
        result = outcome.result
        raw_response = outcome.raw_response
        context_documents = outcome.context_documents
        finish_pipeline_item(current_item.id, status="succeeded")
        current_item = None
        _record_completed_phase_item(
            pipeline_run_id=pipeline_run_id,
            paperless_document_id=run.paperless_document_id,
            item_type="ocr",
            status="succeeded" if outcome.ocr_corrected else "skipped",
        )
        _record_completed_phase_item(
            pipeline_run_id=pipeline_run_id,
            paperless_document_id=run.paperless_document_id,
            item_type="context_search",
            status="succeeded",
        )
        _record_completed_phase_item(
            pipeline_run_id=pipeline_run_id,
            paperless_document_id=run.paperless_document_id,
            item_type="judge",
            status="succeeded" if outcome.judge_verdict and outcome.judge_verdict != "skipped" else "skipped",
        )
        _update_item_derived_progress(
            pipeline_run_id=pipeline_run_id,
            actor_execution_id=actor_execution.id,
            phase="classification",
            message="Document classified by LLM.",
            current_item=f"paperless_document:{run.paperless_document_id}",
        )
        if outcome.ocr_corrected:
            publish_pipeline_event(
                types.DOCUMENT_OCR_CORRECTED,
                pipeline_run_id=pipeline_run_id,
                paperless_document_id=run.paperless_document_id,
                message="OCR correction used for classification input.",
                payload={"corrections": outcome.ocr_corrections},
            )
        else:
            publish_pipeline_event(
                types.DOCUMENT_OCR_SKIPPED,
                pipeline_run_id=pipeline_run_id,
                paperless_document_id=run.paperless_document_id,
                message="OCR correction did not alter classification input.",
            )
        publish_pipeline_event(
            types.DOCUMENT_CONTEXT_SEARCHED,
            pipeline_run_id=pipeline_run_id,
            paperless_document_id=run.paperless_document_id,
            message="Trusted context search completed.",
            payload={"context_count": outcome.context_count},
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
        publish_pipeline_event(
            types.DOCUMENT_JUDGE_COMPLETED,
            pipeline_run_id=pipeline_run_id,
            paperless_document_id=run.paperless_document_id,
            message="Judge verification completed or skipped.",
            payload={"verdict": outcome.judge_verdict},
        )

        _ensure_not_cancelled(pipeline_run_id)
        current_item = _phase_item(
            pipeline_run_id=pipeline_run_id,
            item_type="review_suggestion",
            paperless_document_id=run.paperless_document_id,
        )
        suggestion = store_review_suggestion(
            paperless_document_id=run.paperless_document_id,
            document=outcome.document,
            result=result,
            raw_response=raw_response,
            context_documents=context_documents,
            pipeline_run_id=pipeline_run_id,
            correspondents=outcome.catalog.correspondents,
            doctypes=outcome.catalog.doctypes,
            storage_paths=outcome.catalog.storage_paths,
            tags=outcome.catalog.tags,
            judge_verdict=outcome.judge_verdict,
            judge_reasoning=outcome.judge_reasoning,
            original_proposed_json=outcome.original_proposed_json,
        )
        finish_pipeline_item(current_item.id, status="succeeded")
        current_item = None
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

        final_phase = "review_suggestion"
        final_message = "Document classification is ready for manual review."
        if settings.auto_commit_confidence > 0 and result.confidence >= settings.auto_commit_confidence:
            _ensure_not_cancelled(pipeline_run_id)
            current_item = _phase_item(
                pipeline_run_id=pipeline_run_id,
                item_type="auto_commit",
                paperless_document_id=run.paperless_document_id,
            )
            accepted, unresolved = mark_review_suggestion_auto_accepted(
                suggestion.id,
                reason="auto_commit_confidence",
                confidence=result.confidence,
            )
            if accepted:
                from app.jobs.recovery import enqueue_review_commit

                enqueue_review_commit(suggestion.id)
                finish_pipeline_item(current_item.id, status="succeeded")
                final_phase = "auto_commit"
                final_message = "Document classification was queued for automatic commit."
                publish_pipeline_event(
                    types.DOCUMENT_AUTO_COMMIT_QUEUED,
                    pipeline_run_id=pipeline_run_id,
                    paperless_document_id=run.paperless_document_id,
                    message="Review suggestion auto-accepted and commit actor queued.",
                    payload={"review_suggestion_id": suggestion.id, "confidence": result.confidence},
                )
            else:
                finish_pipeline_item(
                    current_item.id,
                    status="skipped",
                    error="Unresolved entities prevent auto-commit.",
                )
                publish_pipeline_event(
                    types.DOCUMENT_AUTO_COMMIT_SKIPPED,
                    pipeline_run_id=pipeline_run_id,
                    paperless_document_id=run.paperless_document_id,
                    level="warning",
                    message="Auto-commit skipped because proposed entities are unresolved.",
                    payload={"review_suggestion_id": suggestion.id, "unresolved": unresolved},
                )
            current_item = None
            _update_item_derived_progress(
                pipeline_run_id=pipeline_run_id,
                actor_execution_id=actor_execution.id,
                phase=final_phase,
                message=final_message,
                current_item=f"review_suggestion:{suggestion.id}",
            )

        mark_pipeline_run_status(
            pipeline_run_id,
            status="succeeded",
            phase=final_phase,
            message=final_message,
        )
        finish_actor_execution(actor_execution, status="succeeded")
    except DocumentPipelineCancelled as exc:
        finish_actor_execution(
            actor_execution,
            status="cancelled",
            error_type="cancelled",
            error_message=str(exc),
        )
        return
    except Exception as exc:
        if current_item is not None:
            try:
                finish_pipeline_item(current_item.id, status="failed", error=str(exc)[:1000])
            except Exception as item_exc:  # pragma: no cover - preserve original failure
                log.warning("failed to mark pipeline item failed", error=str(item_exc))
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
