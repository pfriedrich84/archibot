"""Webhook actors for the event-driven pipeline."""

from __future__ import annotations

import time

import structlog

from app.dramatiq_broker import dramatiq, queue_name
from app.events import types
from app.events.publish import publish_pipeline_event
from app.jobs.actor_execution import finish_actor_execution, start_actor_execution
from app.jobs.context import worker_id
from app.jobs.pipeline_start import start_or_attach_document_pipeline
from app.jobs.webhook_delivery import load_webhook_delivery, mark_webhook_delivery_status

log = structlog.get_logger(__name__)


def webhook_requests_reprocess(event_type: str) -> bool:
    """Return whether a Paperless webhook should be tracked as automatic reprocess."""
    normalized = event_type.lower().replace(".", "_").replace("-", "_")
    if "delete" in normalized or "trash" in normalized:
        return False
    return any(
        token in normalized
        for token in ["update", "updated", "change", "changed", "modify", "modified"]
    )


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
        publish_pipeline_event(
            types.WEBHOOK_NORMALIZED,
            webhook_delivery_id=webhook_delivery_id,
            paperless_document_id=delivery.paperless_document_id,
            message="Webhook delivery normalized by Dramatiq actor.",
            payload={"event_type": delivery.event_type},
        )
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
        mark_webhook_delivery_status(webhook_delivery_id, delivery_status, result.blocked_reason)
        finish_actor_execution(
            actor_execution,
            status="succeeded" if delivery_status == "processed" else "blocked",
            error_type=result.blocked_reason,
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
            message="Webhook actor failed before starting the document pipeline.",
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
