"""Document processing actors for the event-driven pipeline."""

from __future__ import annotations

import asyncio
import time
from dataclasses import dataclass
from typing import Any

import structlog

from app.actors import LARAVEL_DATABASE_QUEUE
from app.ai_provider.factory import create_ai_provider
from app.clients.paperless import PaperlessClient
from app.events import types
from app.events.publish import publish_pipeline_event
from app.execution_lifecycle import (
    ExecutionLifecycle,
    finish_actor_execution,
    start_actor_execution,
    update_item_derived_progress,
)
from app.jobs.document_embeddings import (
    document_embedding_text,
    find_similar_with_precomputed_embedding,
)
from app.jobs.embedding_gate import ensure_embedding_index_ready
from app.jobs.pipeline_items import (
    PipelineItemRecord,
    finish_pipeline_item,
    start_or_resume_pipeline_item,
)
from app.jobs.pipeline_runs import is_pipeline_run_cancel_requested, load_document_pipeline_run
from app.jobs.review_suggestions import store_review_suggestion
from app.models import ClassificationResult, PaperlessDocument, PaperlessEntity
from app.pipeline.classifier import classify
from app.pipeline.judge import maybe_run_judge
from app.pipeline.ocr_correction import (
    cache_ocr_correction,
    effective_ocr_mode,
    maybe_correct_ocr,
    should_run_ocr_for_document,
)
from app.pipeline.ports import AiProviderGateway, DocumentRepository

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


async def _fetch_paperless_document(
    paperless_document_id: int, paperless: DocumentRepository | None = None
) -> PaperlessDocument:
    if paperless is not None:
        return await paperless.get_document(paperless_document_id)

    paperless_client = PaperlessClient()
    try:
        return await paperless_client.get_document(paperless_document_id)
    finally:
        await paperless_client.aclose()


async def _load_entity_catalog(paperless: DocumentRepository) -> EntityCatalog:
    return EntityCatalog(
        correspondents=await paperless.list_correspondents(),
        doctypes=await paperless.list_document_types(),
        storage_paths=await paperless.list_storage_paths(),
        tags=await paperless.list_tags(),
    )


async def _classify_document(
    document: PaperlessDocument,
    paperless: DocumentRepository | None = None,
    ai_provider: AiProviderGateway | None = None,
) -> DocumentClassificationOutcome:
    owns_paperless = paperless is None
    owns_ai_provider = ai_provider is None
    paperless_client = paperless or PaperlessClient()
    provider = ai_provider or create_ai_provider()
    try:
        catalog = await _load_entity_catalog(paperless_client)
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
                    processed_document, provider, paperless_client
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
                embedding = await provider.embed(text)
                similar = await find_similar_with_precomputed_embedding(
                    processed_document, embedding, paperless_client
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
            provider,
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
            provider,
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
        if owns_ai_provider:
            await provider.aclose()
        if owns_paperless and hasattr(paperless_client, "aclose"):
            await paperless_client.aclose()


def _update_item_derived_progress(
    *,
    pipeline_run_id: int,
    actor_execution_id: int | None,
    phase: str,
    message: str,
    current_item: str | None = None,
) -> None:
    update_item_derived_progress(
        pipeline_run_id=pipeline_run_id,
        actor_execution_id=actor_execution_id,
        phase=phase,
        message=message,
        current_item=current_item,
    )


def start_pipeline_item(
    *, pipeline_run_id: int, item_type: str, paperless_document_id: int | None = None
) -> PipelineItemRecord:
    """Start/resume a document actor phase item by stable key.

    Kept as a module-level wrapper so tests can patch the actor seam without
    patching the lower-level durable item helper.
    """
    item_key = (
        f"{item_type}:{paperless_document_id}" if paperless_document_id is not None else item_type
    )
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


def _ensure_not_cancelled(
    pipeline_run_id: int, current_item: PipelineItemRecord | None = None
) -> None:
    if not is_pipeline_run_cancel_requested(pipeline_run_id):
        return
    if current_item is not None:
        finish_pipeline_item(current_item.id, status="skipped", error="Pipeline run cancelled.")
    publish_pipeline_event(
        types.PIPELINE_CANCELLED,
        pipeline_run_id=pipeline_run_id,
        level="warning",
        message="Pipeline run cancelled by admin request.",
    )
    raise DocumentPipelineCancelled("Pipeline run cancelled by admin request.")


class DocumentPipelineCancelled(Exception):
    """Raised internally when cancellation is observed between phases."""


def _finish_actor_if_cancelled(
    pipeline_run_id: int,
    actor_execution,
    current_item: PipelineItemRecord | None = None,
) -> bool:
    try:
        _ensure_not_cancelled(pipeline_run_id, current_item)
    except DocumentPipelineCancelled as exc:
        finish_actor_execution(
            actor_execution,
            status="cancelled",
            error_type="cancelled",
            error_message=str(exc),
        )
        return True

    return False


def _handle_document_pipeline_impl(
    pipeline_run_id: int, *, embedding_ready: bool | None = None
) -> None:
    """Handle one document pipeline run through durable event-driven steps.

    The productive actor runner supplies ``embedding_ready`` from the exact
    PostgreSQL session that owns the shared pipeline lease. Direct unit calls
    may omit it and retain the ordinary database readiness lookup.
    """
    started = time.monotonic()
    actor_name = "handle_document_pipeline"
    actor_execution = start_actor_execution(
        actor_name=actor_name,
        pipeline_run_id=pipeline_run_id,
        queue_name=LARAVEL_DATABASE_QUEUE,
    )
    log.info(
        "document actor started",
        event_type=types.ACTOR_STARTED,
        pipeline_run_id=pipeline_run_id,
        actor_name=actor_name,
        queue_name=LARAVEL_DATABASE_QUEUE,
    )

    run = None
    current_item: PipelineItemRecord | None = None
    try:
        run = load_document_pipeline_run(pipeline_run_id)
        if run is None:
            message = "Document pipeline run was not found."
            finish_actor_execution(
                actor_execution,
                status="failed_permanent",
                error_type="pipeline_run_not_found",
                error_message=message,
            )
            return

        _ensure_not_cancelled(pipeline_run_id)
        ready = ensure_embedding_index_ready() if embedding_ready is None else embedding_ready
        if not ready:
            message = "Document actor blocked because the embedding index is not ready."
            _ensure_not_cancelled(pipeline_run_id)
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

        _ensure_not_cancelled(pipeline_run_id)
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
            status="succeeded"
            if outcome.judge_verdict and outcome.judge_verdict != "skipped"
            else "skipped",
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

        _ensure_not_cancelled(pipeline_run_id)
        _ensure_not_cancelled(pipeline_run_id)
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
        if _finish_actor_if_cancelled(pipeline_run_id, actor_execution, current_item):
            return
        if current_item is not None:
            try:
                finish_pipeline_item(current_item.id, status="failed", error=str(exc)[:1000])
                current_item = None
            except Exception as item_exc:  # pragma: no cover - preserve original failure
                log.warning("failed to mark pipeline item failed", error=str(item_exc))
        if _finish_actor_if_cancelled(pipeline_run_id, actor_execution):
            return
        max_attempts = 5 if run is None else run.max_retries
        disposition = ExecutionLifecycle(actor_execution).fail(exc, max_attempts=max_attempts)
        if disposition.retrying:
            return
        raise

    log.info(
        "document actor completed with review suggestion",
        event_type=types.ACTOR_SUCCEEDED,
        pipeline_run_id=pipeline_run_id,
        actor_name=actor_name,
        queue_name=LARAVEL_DATABASE_QUEUE,
        duration_ms=int((time.monotonic() - started) * 1000),
    )
