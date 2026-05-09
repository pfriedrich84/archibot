"""PostgreSQL-backed pipeline event publishing helpers."""

from __future__ import annotations

from typing import Any

import structlog

log = structlog.get_logger(__name__)


def publish_pipeline_event(
    event_type: str,
    *,
    pipeline_run_id: int | None = None,
    webhook_delivery_id: int | None = None,
    paperless_document_id: int | None = None,
    level: str = "info",
    message: str | None = None,
    payload: dict[str, Any] | None = None,
) -> None:
    """Publish a pipeline event.

    The first migration step keeps this helper intentionally small. Once the
    PostgreSQL session layer is fully wired, this function will insert into
    `pipeline_events`. For now it mirrors the contract to structured logs so
    actors can be built against one helper without logging secrets/content.
    """
    log.bind(
        event_type=event_type,
        pipeline_run_id=pipeline_run_id,
        webhook_delivery_id=webhook_delivery_id,
        paperless_document_id=paperless_document_id,
        **(payload or {}),
    ).log(level, message or event_type)
