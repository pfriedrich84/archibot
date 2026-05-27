"""PostgreSQL helpers for Laravel review suggestion persistence."""

from __future__ import annotations

import json
from dataclasses import dataclass
from datetime import date, datetime
from typing import Any

from app.jobs.database import engine
from app.models import ClassificationResult


@dataclass(frozen=True)
class StoredReviewSuggestion:
    id: int
    status: str


def sql_text(statement: str):
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError(
            "sqlalchemy is required for PostgreSQL-backed review suggestions"
        ) from exc

    return text(statement)


def _json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, default=_json_default)


def _json_default(value: object) -> str:
    if isinstance(value, datetime | date):
        return value.isoformat()
    return str(value)


def store_review_suggestion(
    *,
    paperless_document_id: int,
    document: Any,
    result: ClassificationResult,
    raw_response: str,
    context_documents: list[dict[str, Any]] | None = None,
    pipeline_run_id: int | None = None,
) -> StoredReviewSuggestion:
    """Persist a pending Laravel review suggestion from an event-driven actor."""
    proposed_tags = [tag.model_dump() for tag in result.tags]
    raw_payload: Any
    try:
        raw_payload = json.loads(raw_response)
    except json.JSONDecodeError:
        raw_payload = {"raw": raw_response}

    statement = sql_text(
        """
        INSERT INTO review_suggestions (
            paperless_document_id,
            status,
            confidence,
            reasoning,
            original_title,
            original_date,
            original_correspondent_id,
            original_document_type_id,
            original_storage_path_id,
            original_tags,
            proposed_title,
            proposed_date,
            proposed_correspondent_name,
            proposed_document_type_name,
            proposed_storage_path_name,
            proposed_tags,
            context_documents,
            raw_response,
            created_at,
            updated_at
        ) VALUES (
            :paperless_document_id,
            'pending',
            :confidence,
            :reasoning,
            :original_title,
            :original_date,
            :original_correspondent_id,
            :original_document_type_id,
            :original_storage_path_id,
            CAST(:original_tags AS jsonb),
            :proposed_title,
            :proposed_date,
            :proposed_correspondent_name,
            :proposed_document_type_name,
            :proposed_storage_path_name,
            CAST(:proposed_tags AS jsonb),
            CAST(:context_documents AS jsonb),
            CAST(:raw_response AS jsonb),
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
        RETURNING id, status
        """
    )
    with engine().begin() as connection:
        row = (
            connection.execute(
                statement,
                {
                    "paperless_document_id": paperless_document_id,
                    "confidence": result.confidence,
                    "reasoning": result.reasoning,
                    "original_title": getattr(document, "title", None),
                    "original_date": getattr(document, "created_date", None),
                    "original_correspondent_id": getattr(document, "correspondent", None),
                    "original_document_type_id": getattr(document, "document_type", None),
                    "original_storage_path_id": getattr(document, "storage_path", None),
                    "original_tags": _json(getattr(document, "tags", []) or []),
                    "proposed_title": result.title,
                    "proposed_date": result.date,
                    "proposed_correspondent_name": result.correspondent,
                    "proposed_document_type_name": result.document_type,
                    "proposed_storage_path_name": result.storage_path,
                    "proposed_tags": _json(proposed_tags),
                    "context_documents": _json(context_documents or []),
                    "raw_response": _json(raw_payload),
                    "pipeline_run_id": pipeline_run_id,
                },
            )
            .mappings()
            .first()
        )

    if row is None:  # pragma: no cover - PostgreSQL RETURNING should always return here
        raise RuntimeError("review suggestion insert did not return a row")

    return StoredReviewSuggestion(id=int(row["id"]), status=str(row["status"]))
