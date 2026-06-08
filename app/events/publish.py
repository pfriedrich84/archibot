"""PostgreSQL-backed pipeline event publishing helpers."""

from __future__ import annotations

import json
from datetime import date, datetime
from typing import Any

import structlog

from app.jobs.database import engine

log = structlog.get_logger(__name__)


def sql_text(statement: str):
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError("sqlalchemy is required for PostgreSQL-backed pipeline events") from exc

    return text(statement)


def _json_default(value: object) -> str:
    if isinstance(value, datetime | date):
        return value.isoformat()
    return str(value)


def _payload_json(payload: dict[str, Any] | None) -> str:
    return json.dumps(payload or {}, ensure_ascii=False, default=_json_default)


def _log_level_method(level: str) -> str:
    """Return a structlog method name for a durable event level string."""
    normalized = str(level or "info").strip().lower()
    return {
        "debug": "debug",
        "info": "info",
        "success": "info",
        "warning": "warning",
        "warn": "warning",
        "error": "error",
        "critical": "critical",
    }.get(normalized, "info")


def publish_pipeline_event(
    event_type: str,
    *,
    pipeline_run_id: int | None = None,
    webhook_delivery_id: int | None = None,
    command_id: int | None = None,
    paperless_document_id: int | None = None,
    level: str = "info",
    message: str | None = None,
    payload: dict[str, Any] | None = None,
) -> None:
    """Publish a durable pipeline event and mirror it to structured logs.

    Callers must keep payloads redacted: identifiers, counts, distances,
    statuses, and short error summaries only. Do not include full OCR text,
    document content, prompts, secrets, or raw LLM responses.
    """
    statement = sql_text(
        """
        INSERT INTO pipeline_events (
            pipeline_run_id,
            webhook_delivery_id,
            command_id,
            event_type,
            paperless_document_id,
            level,
            message,
            payload,
            created_at
        ) VALUES (
            :pipeline_run_id,
            :webhook_delivery_id,
            :command_id,
            :event_type,
            :paperless_document_id,
            :level,
            :message,
            CAST(:payload AS jsonb),
            CURRENT_TIMESTAMP
        )
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {
                "pipeline_run_id": pipeline_run_id,
                "webhook_delivery_id": webhook_delivery_id,
                "command_id": command_id,
                "event_type": event_type,
                "paperless_document_id": paperless_document_id,
                "level": level,
                "message": message,
                "payload": _payload_json(payload),
            },
        )

    bound_log = log.bind(
        event_type=event_type,
        pipeline_run_id=pipeline_run_id,
        webhook_delivery_id=webhook_delivery_id,
        command_id=command_id,
        paperless_document_id=paperless_document_id,
        **(payload or {}),
    )
    getattr(bound_log, _log_level_method(level))(message or event_type)
