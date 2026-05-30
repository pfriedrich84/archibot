"""Webhook actors for the event-driven pipeline."""

from __future__ import annotations

import asyncio
import time
from dataclasses import dataclass

import structlog

from app.ai_provider.factory import create_ai_provider
from app.clients.paperless import PaperlessClient
from app.dramatiq_broker import dramatiq, queue_name
from app.events import types
from app.events.publish import publish_pipeline_event
from app.jobs.actor_execution import finish_actor_execution, start_actor_execution
from app.jobs.context import worker_id
from app.jobs.document_embeddings import (
    DocumentEmbeddingInput,
    delete_document_embeddings_for_document,
    delete_stale_document_embeddings_for_document,
    document_embedding_text,
    is_trusted_document,
    store_document_embedding,
)
from app.jobs.embedding_gate import ensure_embedding_index_ready
from app.jobs.pipeline_start import start_or_attach_document_pipeline
from app.jobs.webhook_delivery import load_webhook_delivery, mark_webhook_delivery_status
from app.pipeline.ocr_correction import cache_ocr_correction, effective_ocr_mode, maybe_correct_ocr

log = structlog.get_logger(__name__)


@dataclass(frozen=True)
class EmbeddingRefreshResult:
    status: str
    blocked_reason: str | None = None
    skipped_reason: str | None = None
    content_hash: str | None = None
    trusted_for_context: bool | None = None


def _normalized_event_type(event_type: str) -> str:
    return event_type.lower().replace(".", "_").replace("-", "_").replace(" ", "_")


def _contains_any(value: str, tokens: list[str]) -> bool:
    return any(token in value for token in tokens)


def webhook_action(event_type: str) -> str:
    """Return the durable action for a Paperless webhook event type.

    Creation/consume events start the full ArchiBot classification pipeline.
    Update/edit events only refresh the pgvector embedding so ArchiBot's own
    Paperless write-backs do not create classification feedback loops.
    """
    normalized = _normalized_event_type(event_type)
    if _contains_any(normalized, ["delete", "deleted", "trash", "trashed"]):
        return "delete_embedding"
    if _contains_any(
        normalized,
        ["create", "created", "added", "new", "consume", "consumed", "import", "imported"],
    ):
        return "process_document"
    if _contains_any(
        normalized,
        ["update", "updated", "change", "changed", "modify", "modified", "edit", "edited"],
    ):
        return "refresh_embedding"
    return "process_document"


def webhook_starts_document_pipeline(event_type: str) -> bool:
    return webhook_action(event_type) == "process_document"


def webhook_refreshes_embedding(event_type: str) -> bool:
    return webhook_action(event_type) == "refresh_embedding"


def webhook_deletes_embedding(event_type: str) -> bool:
    return webhook_action(event_type) == "delete_embedding"


def webhook_requests_reprocess(event_type: str) -> bool:
    """Return whether the webhook should force a full document reprocess.

    Automatic Paperless edit/update webhooks intentionally do not force full
    reprocessing. They refresh embeddings only; full reprocess remains explicit
    via creation events, polling for missing work, or an admin action.
    """
    return False


async def _refresh_document_embedding_async(paperless_document_id: int) -> EmbeddingRefreshResult:
    paperless = PaperlessClient()
    provider = create_ai_provider()
    try:
        document = await paperless.get_document(paperless_document_id)
        processed_document = document
        ocr_mode = effective_ocr_mode()
        corrected_text, ocr_corrections = await maybe_correct_ocr(document, provider, paperless)
        if ocr_corrections > 0:
            processed_document = document.model_copy(update={"content": corrected_text})
            cache_ocr_correction(
                processed_document.id,
                corrected_text,
                ocr_mode,
                ocr_corrections,
            )

        text = document_embedding_text(processed_document.title, processed_document.content)
        if not text.strip():
            publish_pipeline_event(
                types.DOCUMENT_EMBEDDING_REFRESH_SKIPPED,
                paperless_document_id=paperless_document_id,
                message="Document embedding refresh skipped because the document has no text.",
                payload={"reason": "empty_embedding_text"},
            )
            return EmbeddingRefreshResult(status="processed", skipped_reason="empty_embedding_text")

        embedding = await provider.embed(text)
        trusted_for_context = is_trusted_document(processed_document)
        content_hash = store_document_embedding(
            DocumentEmbeddingInput(
                paperless_document_id=processed_document.id,
                title=processed_document.title,
                content=processed_document.content,
                embedding_model=provider.embed_model,
                embedding=embedding,
                created_date=processed_document.created_date,
                metadata={
                    "correspondent": processed_document.correspondent,
                    "document_type": processed_document.document_type,
                    "storage_path": processed_document.storage_path,
                    "tags": processed_document.tags,
                    "modified": processed_document.modified,
                },
                correspondent_id=processed_document.correspondent,
                document_type_id=processed_document.document_type,
                storage_path_id=processed_document.storage_path,
                tags=processed_document.tags,
                paperless_modified=str(processed_document.modified)
                if processed_document.modified is not None
                else None,
                trusted_for_context=trusted_for_context,
            )
        )
        stale_deleted = 0
        if content_hash is not None:
            stale_deleted = delete_stale_document_embeddings_for_document(
                paperless_document_id=processed_document.id,
                keep_content_hash=content_hash,
                embedding_model=provider.embed_model,
                dimensions=len(embedding),
            )
        publish_pipeline_event(
            types.DOCUMENT_EMBEDDING_REFRESHED,
            paperless_document_id=paperless_document_id,
            message="Document embedding refreshed from Paperless update webhook.",
            payload={
                "content_hash": content_hash,
                "trusted_for_context": trusted_for_context,
                "ocr_corrected": ocr_corrections > 0,
                "stale_embeddings_deleted": stale_deleted,
            },
        )
        return EmbeddingRefreshResult(
            status="processed",
            content_hash=content_hash,
            trusted_for_context=trusted_for_context,
        )
    finally:
        await provider.aclose()
        await paperless.aclose()


def refresh_document_embedding(paperless_document_id: int) -> EmbeddingRefreshResult:
    """Refresh one document embedding, respecting the global embedding gate."""
    if not ensure_embedding_index_ready():
        return EmbeddingRefreshResult(
            status="blocked",
            blocked_reason="embedding_index_not_ready",
        )

    return asyncio.run(_refresh_document_embedding_async(paperless_document_id))


def _delete_document_embedding(paperless_document_id: int) -> int:
    deleted = delete_document_embeddings_for_document(paperless_document_id)
    publish_pipeline_event(
        types.DOCUMENT_EMBEDDING_DELETED,
        paperless_document_id=paperless_document_id,
        message="Document embeddings removed after Paperless delete/trash webhook.",
        payload={"deleted_rows": deleted},
    )
    return deleted


def _handle_paperless_webhook_impl(webhook_delivery_id: int) -> None:
    started = time.monotonic()
    actor_name = "handle_paperless_webhook"
    log.info(
        "webhook actor started",
        event_type=types.ACTOR_STARTED,
        webhook_delivery_id=webhook_delivery_id,
        actor_name=actor_name,
        queue_name=queue_name("webhook"),
        worker_id=worker_id(),
    )

    actor_execution = None
    try:
        delivery = load_webhook_delivery(webhook_delivery_id)
        if delivery is None:
            publish_pipeline_event(
                types.ACTOR_FAILED,
                webhook_delivery_id=webhook_delivery_id,
                level="error",
                message="Webhook delivery was not found for actor execution.",
                payload={"actor_name": actor_name},
            )
            return

        actor_execution = start_actor_execution(
            actor_name=actor_name,
            paperless_document_id=delivery.paperless_document_id,
            queue_name=queue_name("webhook"),
        )
        action = webhook_action(delivery.event_type)
        publish_pipeline_event(
            types.WEBHOOK_NORMALIZED,
            webhook_delivery_id=webhook_delivery_id,
            paperless_document_id=delivery.paperless_document_id,
            message="Webhook delivery normalized by Dramatiq actor.",
            payload={"event_type": delivery.event_type, "webhook_action": action},
        )

        if action == "process_document":
            reprocess_requested = webhook_requests_reprocess(delivery.event_type)
            result = start_or_attach_document_pipeline(
                trigger_source="webhook",
                paperless_document_id=delivery.paperless_document_id,
                paperless_modified=delivery.paperless_modified,
                reprocess_requested=reprocess_requested,
                reprocess_reason=delivery.event_type if reprocess_requested else None,
                reprocess_mode="webhook" if reprocess_requested else None,
                webhook_delivery_id=webhook_delivery_id,
            )
            delivery_status = "blocked" if result.status == "blocked" else "processed"
            mark_webhook_delivery_status(
                webhook_delivery_id, delivery_status, result.blocked_reason
            )
            finish_actor_execution(
                actor_execution,
                status="succeeded" if delivery_status == "processed" else "blocked",
                error_type=result.blocked_reason,
            )
        elif action == "refresh_embedding":
            refresh = refresh_document_embedding(delivery.paperless_document_id)
            mark_webhook_delivery_status(
                webhook_delivery_id, refresh.status, refresh.blocked_reason
            )
            finish_actor_execution(
                actor_execution,
                status="succeeded" if refresh.status == "processed" else "blocked",
                error_type=refresh.blocked_reason or refresh.skipped_reason,
            )
            if refresh.status == "blocked":
                publish_pipeline_event(
                    types.PIPELINE_BLOCKED_EMBEDDING_NOT_READY,
                    webhook_delivery_id=webhook_delivery_id,
                    paperless_document_id=delivery.paperless_document_id,
                    level="warning",
                    message="Embedding refresh webhook blocked because the embedding index is not ready.",
                )
        else:
            deleted = _delete_document_embedding(delivery.paperless_document_id)
            mark_webhook_delivery_status(webhook_delivery_id, "processed", None)
            finish_actor_execution(actor_execution, status="succeeded")
            log.info(
                "webhook deleted document embeddings",
                webhook_delivery_id=webhook_delivery_id,
                paperless_document_id=delivery.paperless_document_id,
                deleted_rows=deleted,
            )
    except Exception as exc:
        mark_webhook_delivery_status(webhook_delivery_id, "failed", type(exc).__name__)
        if actor_execution is not None:
            finish_actor_execution(
                actor_execution,
                status="failed",
                error_type=type(exc).__name__,
                error_message=str(exc)[:1000],
            )
        publish_pipeline_event(
            types.ACTOR_FAILED,
            webhook_delivery_id=webhook_delivery_id,
            level="error",
            message="Webhook actor failed before completing the requested webhook action.",
            payload={"actor_name": actor_name, "error_type": type(exc).__name__},
        )
        raise

    log.info(
        "webhook actor succeeded",
        event_type=types.ACTOR_SUCCEEDED,
        webhook_delivery_id=webhook_delivery_id,
        actor_name=actor_name,
        queue_name=queue_name("webhook"),
        duration_ms=int((time.monotonic() - started) * 1000),
    )


if dramatiq is not None:
    handle_paperless_webhook = dramatiq.actor(queue_name=queue_name("webhook"))(
        _handle_paperless_webhook_impl
    )
else:  # pragma: no cover - lets local imports work before deps are installed
    handle_paperless_webhook = _handle_paperless_webhook_impl
