"""PostgreSQL helpers for Laravel review suggestion persistence."""

from __future__ import annotations

import json
from dataclasses import dataclass
from datetime import date, datetime
from typing import Any

from app.jobs.database import engine
from app.models import (
    ClassificationResult,
    PaperlessEntity,
    document_date_for,
    document_version_checksum_for,
    document_version_id_for,
)


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
    if hasattr(value, "model_dump"):
        return value.model_dump()
    if hasattr(value, "__dict__"):
        return vars(value)
    return str(value)


def _safe_context_documents(context_documents: list[Any] | None) -> list[dict[str, Any]]:
    items: list[dict[str, Any]] = []
    for item in context_documents or []:
        document = getattr(item, "document", item)
        items.append(
            {
                "id": getattr(document, "id", None),
                "title": getattr(document, "title", None),
                "distance": getattr(item, "distance", None),
            }
        )
    return items


def _entity_id(name: str | None, entities: list[PaperlessEntity] | None) -> int | None:
    if not name:
        return None
    normalized = name.strip().casefold()
    if not normalized:
        return None
    for entity in entities or []:
        if entity.name.strip().casefold() == normalized:
            return int(entity.id)
    return None


def _proposed_tags(
    result: ClassificationResult, tags: list[PaperlessEntity] | None
) -> list[dict[str, Any]]:
    proposed: list[dict[str, Any]] = []
    for tag in result.tags:
        item = tag.model_dump()
        tag_id = _entity_id(tag.name, tags)
        if tag_id is not None:
            item["id"] = tag_id
        proposed.append(item)
    return proposed


def _raw_payload(raw_response: str) -> Any:
    try:
        return json.loads(raw_response)
    except json.JSONDecodeError:
        return {"raw": raw_response}


def _upsert_entity_approval(
    *,
    connection,
    suggestion_id: int,
    entity_type: str,
    name: str | None,
    resolved_id: int | None,
) -> None:
    if not name or resolved_id is not None:
        return
    clean_name = name.strip()
    if not clean_name:
        return
    params = {"type": entity_type, "name": clean_name, "source_review_suggestion_id": suggestion_id}
    update = sql_text(
        """
        UPDATE entity_approvals
        SET source_review_suggestion_id = COALESCE(
                source_review_suggestion_id,
                :source_review_suggestion_id
            ),
            updated_at = CURRENT_TIMESTAMP
        WHERE type = :type
          AND name = :name
        """
    )
    result = connection.execute(update, params)
    if getattr(result, "rowcount", 0):
        return

    insert = sql_text(
        """
        INSERT INTO entity_approvals (
            type,
            name,
            status,
            source_review_suggestion_id,
            created_at,
            updated_at
        ) VALUES (
            :type,
            :name,
            'pending',
            :source_review_suggestion_id,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
        """
    )
    connection.execute(insert, params)


def classified_document_ids(paperless_document_ids: list[int]) -> set[int]:
    """Return Inbox Documents that already have a durable classification marker.

    A Review Suggestion is written only after classification succeeds. Its
    existence therefore remains a stable poll marker even when ArchiBot or a
    user later changes Paperless metadata (and Paperless changes ``modified``).
    Review status is intentionally irrelevant: pending, accepted, rejected,
    committed and commit-error suggestions all represent completed
    classification work. Manual force reprocess and webhook starts do not call
    this poll-only helper.
    """
    document_ids = sorted({int(document_id) for document_id in paperless_document_ids})
    if not document_ids:
        return set()

    statement = sql_text(
        """
        SELECT DISTINCT paperless_document_id
        FROM review_suggestions
        WHERE paperless_document_id = ANY(CAST(:paperless_document_ids AS BIGINT[]))
        """
    )
    with engine().connect() as connection:
        rows = (
            connection.execute(statement, {"paperless_document_ids": document_ids}).mappings().all()
        )

    return {int(row["paperless_document_id"]) for row in rows}


def store_review_suggestion(
    *,
    paperless_document_id: int,
    document: Any,
    result: ClassificationResult,
    raw_response: str,
    context_documents: list[Any] | None = None,
    pipeline_run_id: int | None = None,
    correspondents: list[PaperlessEntity] | None = None,
    doctypes: list[PaperlessEntity] | None = None,
    storage_paths: list[PaperlessEntity] | None = None,
    tags: list[PaperlessEntity] | None = None,
    judge_verdict: str | None = None,
    judge_reasoning: str | None = None,
    original_proposed_json: str | None = None,
) -> StoredReviewSuggestion:
    """Persist or update a Laravel review suggestion from an event-driven actor."""
    proposed_correspondent_id = _entity_id(result.correspondent, correspondents)
    proposed_document_type_id = _entity_id(result.document_type, doctypes)
    proposed_storage_path_id = _entity_id(result.storage_path, storage_paths)
    proposed_tags = _proposed_tags(result, tags)
    dedupe_key = f"pipeline_run:{pipeline_run_id}" if pipeline_run_id is not None else None

    statement = sql_text(
        """
        INSERT INTO review_suggestions (
            dedupe_key,
            pipeline_run_id,
            paperless_document_id,
            paperless_version_id,
            paperless_version_checksum,
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
            proposed_correspondent_id,
            proposed_document_type_name,
            proposed_document_type_id,
            proposed_storage_path_name,
            proposed_storage_path_id,
            proposed_tags,
            context_documents,
            raw_response,
            judge_verdict,
            judge_reasoning,
            original_proposed_snapshot,
            created_at,
            updated_at
        ) VALUES (
            :dedupe_key,
            :pipeline_run_id,
            :paperless_document_id,
            :paperless_version_id,
            :paperless_version_checksum,
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
            :proposed_correspondent_id,
            :proposed_document_type_name,
            :proposed_document_type_id,
            :proposed_storage_path_name,
            :proposed_storage_path_id,
            CAST(:proposed_tags AS jsonb),
            CAST(:context_documents AS jsonb),
            CAST(:raw_response AS jsonb),
            :judge_verdict,
            :judge_reasoning,
            CAST(:original_proposed_snapshot AS jsonb),
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
        ON CONFLICT (dedupe_key)
        DO UPDATE SET
            confidence = EXCLUDED.confidence,
            reasoning = EXCLUDED.reasoning,
            proposed_title = EXCLUDED.proposed_title,
            proposed_date = EXCLUDED.proposed_date,
            proposed_correspondent_name = EXCLUDED.proposed_correspondent_name,
            proposed_correspondent_id = EXCLUDED.proposed_correspondent_id,
            proposed_document_type_name = EXCLUDED.proposed_document_type_name,
            proposed_document_type_id = EXCLUDED.proposed_document_type_id,
            proposed_storage_path_name = EXCLUDED.proposed_storage_path_name,
            proposed_storage_path_id = EXCLUDED.proposed_storage_path_id,
            proposed_tags = EXCLUDED.proposed_tags,
            context_documents = EXCLUDED.context_documents,
            raw_response = EXCLUDED.raw_response,
            judge_verdict = EXCLUDED.judge_verdict,
            judge_reasoning = EXCLUDED.judge_reasoning,
            original_proposed_snapshot = EXCLUDED.original_proposed_snapshot,
            updated_at = CURRENT_TIMESTAMP
        WHERE review_suggestions.status = 'pending'
        RETURNING id, status
        """
    )
    existing_statement = sql_text(
        """
        SELECT id, status
        FROM review_suggestions
        WHERE dedupe_key = :dedupe_key
        """
    )
    original_snapshot: Any = None
    if original_proposed_json:
        try:
            original_snapshot = json.loads(original_proposed_json)
        except json.JSONDecodeError:
            original_snapshot = {"raw": original_proposed_json}

    with engine().begin() as connection:
        row = (
            connection.execute(
                statement,
                {
                    "dedupe_key": dedupe_key,
                    "pipeline_run_id": pipeline_run_id,
                    "paperless_document_id": paperless_document_id,
                    "paperless_version_id": document_version_id_for(document),
                    "paperless_version_checksum": document_version_checksum_for(document),
                    "confidence": result.confidence,
                    "reasoning": result.reasoning,
                    "original_title": getattr(document, "title", None),
                    "original_date": document_date_for(document),
                    "original_correspondent_id": getattr(document, "correspondent", None),
                    "original_document_type_id": getattr(document, "document_type", None),
                    "original_storage_path_id": getattr(document, "storage_path", None),
                    "original_tags": _json(getattr(document, "tags", []) or []),
                    "proposed_title": result.title,
                    "proposed_date": result.date,
                    "proposed_correspondent_name": result.correspondent,
                    "proposed_correspondent_id": proposed_correspondent_id,
                    "proposed_document_type_name": result.document_type,
                    "proposed_document_type_id": proposed_document_type_id,
                    "proposed_storage_path_name": result.storage_path,
                    "proposed_storage_path_id": proposed_storage_path_id,
                    "proposed_tags": _json(proposed_tags),
                    "context_documents": _json(_safe_context_documents(context_documents)),
                    "raw_response": _json(_raw_payload(raw_response)),
                    "judge_verdict": judge_verdict,
                    "judge_reasoning": judge_reasoning,
                    "original_proposed_snapshot": _json(original_snapshot),
                },
            )
            .mappings()
            .first()
        )
        if row is None:
            row = (
                connection.execute(existing_statement, {"dedupe_key": dedupe_key})
                .mappings()
                .first()
            )
        if row is None:  # pragma: no cover - PostgreSQL should return inserted or existing row
            raise RuntimeError("review suggestion upsert did not return a row")
        suggestion_id = int(row["id"])
        if str(row["status"]) != "pending":
            return StoredReviewSuggestion(id=suggestion_id, status=str(row["status"]))
        _upsert_entity_approval(
            connection=connection,
            suggestion_id=suggestion_id,
            entity_type="correspondent",
            name=result.correspondent,
            resolved_id=proposed_correspondent_id,
        )
        _upsert_entity_approval(
            connection=connection,
            suggestion_id=suggestion_id,
            entity_type="document_type",
            name=result.document_type,
            resolved_id=proposed_document_type_id,
        )
        for tag in proposed_tags:
            _upsert_entity_approval(
                connection=connection,
                suggestion_id=suggestion_id,
                entity_type="tag",
                name=tag.get("name"),
                resolved_id=tag.get("id"),
            )

    return StoredReviewSuggestion(id=suggestion_id, status=str(row["status"]))
