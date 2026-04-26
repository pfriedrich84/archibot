"""JSON API routes used by the new SvelteKit admin frontend."""

from __future__ import annotations

import asyncio
import contextlib
import json
from typing import Annotated, Any

from fastapi import APIRouter, Body, HTTPException, Query, Request

from app.api_data import (
    get_chat_snapshot,
    get_dashboard_snapshot,
    get_embeddings_snapshot,
    get_inbox_snapshot,
    get_recent_errors,
    get_review_queue,
    get_settings_schema,
    get_stats_snapshot,
    get_system_status,
    get_tags_snapshot,
)
from app.config_writer import apply_runtime_changes, save_config
from app.db import get_conn
from app.models import ReviewDecision, SuggestionRow
from app.pipeline.committer import commit_suggestion

router = APIRouter(prefix="/api/v1", tags=["api"])


def _row_to_suggestion(row: Any) -> SuggestionRow:
    return SuggestionRow(**dict(row))


def _json_list(value: str | None) -> list[Any]:
    if not value:
        return []
    with contextlib.suppress(json.JSONDecodeError, TypeError):
        parsed = json.loads(value)
        if isinstance(parsed, list):
            return parsed
    return []


def _json_object(value: str | None) -> dict[str, Any] | None:
    if not value:
        return None
    with contextlib.suppress(json.JSONDecodeError, TypeError):
        parsed = json.loads(value)
        if isinstance(parsed, dict):
            return parsed
    return None


def _coerce_optional_int(value: Any) -> int | None:
    if value in (None, ""):
        return None
    return int(value)


def _coerce_tag_ids(value: Any) -> list[int]:
    if not isinstance(value, list):
        return []
    tag_ids: list[int] = []
    for item in value:
        with contextlib.suppress(TypeError, ValueError):
            tag_ids.append(int(item))
    return list(dict.fromkeys(tag_ids))


async def _load_review_lookups(request: Request) -> dict[str, list[dict[str, Any]]]:
    paperless = request.app.state.paperless
    correspondents, doctypes, storage_paths, tags = await asyncio.gather(
        paperless.list_correspondents(),
        paperless.list_document_types(),
        paperless.list_storage_paths(),
        paperless.list_tags(),
    )
    return {
        "correspondents": [{"id": item.id, "name": item.name} for item in correspondents],
        "doctypes": [{"id": item.id, "name": item.name} for item in doctypes],
        "storage_paths": [{"id": item.id, "name": item.name} for item in storage_paths],
        "tags": [{"id": item.id, "name": item.name} for item in tags],
    }


async def _save_review_payload(
    request: Request, suggestion: SuggestionRow, payload: dict[str, Any]
) -> SuggestionRow:
    lookups = await _load_review_lookups(request)
    correspondent_lookup = {item["id"]: item["name"] for item in lookups["correspondents"]}
    doctype_lookup = {item["id"]: item["name"] for item in lookups["doctypes"]}
    storage_lookup = {item["id"]: item["name"] for item in lookups["storage_paths"]}
    tag_lookup = {item["id"]: item["name"] for item in lookups["tags"]}

    title = str(payload.get("title") or suggestion.proposed_title or suggestion.original_title or "")
    date = str(payload.get("date") or "").strip() or suggestion.effective_date
    correspondent_id = _coerce_optional_int(payload.get("correspondent_id"))
    doctype_id = _coerce_optional_int(payload.get("doctype_id"))
    storage_path_id = _coerce_optional_int(payload.get("storage_path_id"))
    tag_ids = _coerce_tag_ids(payload.get("tag_ids"))

    unresolved_tags = [
        {"id": None, "name": tag.get("name"), "confidence": tag.get("confidence")}
        for tag in _json_list(suggestion.proposed_tags_json)
        if isinstance(tag, dict) and tag.get("id") is None and tag.get("name")
    ]
    tag_dicts = [
        {"id": tag_id, "name": tag_lookup.get(tag_id, f"Tag #{tag_id}")}
        for tag_id in tag_ids
    ] + unresolved_tags

    correspondent_name = correspondent_lookup.get(correspondent_id)
    if correspondent_id is None and suggestion.proposed_correspondent_id is None:
        correspondent_name = suggestion.proposed_correspondent_name

    doctype_name = doctype_lookup.get(doctype_id)
    if doctype_id is None and suggestion.proposed_doctype_id is None:
        doctype_name = suggestion.proposed_doctype_name

    storage_path_name = storage_lookup.get(storage_path_id)
    if storage_path_id is None and suggestion.proposed_storage_path_id is None:
        storage_path_name = suggestion.proposed_storage_path_name

    with get_conn() as conn:
        conn.execute(
            """
            UPDATE suggestions SET
                proposed_title = ?,
                proposed_date = ?,
                proposed_correspondent_id = ?,
                proposed_correspondent_name = ?,
                proposed_doctype_id = ?,
                proposed_doctype_name = ?,
                proposed_storage_path_id = ?,
                proposed_storage_path_name = ?,
                proposed_tags_json = ?
            WHERE id = ?
            """,
            (
                title,
                date,
                correspondent_id,
                correspondent_name,
                doctype_id,
                doctype_name,
                storage_path_id,
                storage_path_name,
                json.dumps(tag_dicts, ensure_ascii=False),
                suggestion.id,
            ),
        )
        row = conn.execute("SELECT * FROM suggestions WHERE id = ?", (suggestion.id,)).fetchone()

    if not row:
        raise HTTPException(status_code=404, detail="Suggestion not found")
    return _row_to_suggestion(row)


@router.get("/dashboard")
async def dashboard_api(request: Request) -> dict[str, Any]:
    return get_dashboard_snapshot(request.app)


@router.get("/system/status")
async def system_status_api(request: Request) -> dict[str, Any]:
    return get_system_status(request.app)


@router.get("/errors/recent")
async def recent_errors_api(limit: int = Query(default=20, ge=1, le=100)) -> dict[str, Any]:
    return {"items": get_recent_errors(limit=limit)}


@router.get("/review/queue")
async def review_queue_api(limit: int = Query(default=100, ge=1, le=500)) -> dict[str, Any]:
    return get_review_queue(limit=limit)


@router.get("/review/{suggestion_id}")
async def review_detail_api(request: Request, suggestion_id: int) -> dict[str, Any]:
    with get_conn() as conn:
        row = conn.execute("SELECT * FROM suggestions WHERE id = ?", (suggestion_id,)).fetchone()
    if not row:
        raise HTTPException(status_code=404, detail="Suggestion not found")

    suggestion = _row_to_suggestion(row)
    lookups = await _load_review_lookups(request)
    correspondent_lookup = {item["id"]: item["name"] for item in lookups["correspondents"]}
    doctype_lookup = {item["id"]: item["name"] for item in lookups["doctypes"]}
    storage_lookup = {item["id"]: item["name"] for item in lookups["storage_paths"]}
    tag_lookup = {item["id"]: item["name"] for item in lookups["tags"]}

    original_tags = [
        {"id": tag_id, "name": tag_lookup.get(tag_id, f"Tag #{tag_id}")}
        for tag_id in _json_list(suggestion.original_tags_json)
        if isinstance(tag_id, int)
    ]
    proposed_tags = []
    for tag in _json_list(suggestion.proposed_tags_json):
        if not isinstance(tag, dict):
            continue
        tag_id = tag.get("id")
        tag_name = tag.get("name") or (tag_lookup.get(tag_id) if isinstance(tag_id, int) else None) or "Unbekannt"
        proposed_tags.append(
            {"id": tag_id, "name": tag_name, "confidence": tag.get("confidence")}
        )

    return {
        "suggestion": {
            "id": suggestion.id,
            "document_id": suggestion.document_id,
            "created_at": suggestion.created_at,
            "status": suggestion.status,
            "confidence": suggestion.confidence,
            "reasoning": suggestion.reasoning,
            "judge_verdict": suggestion.judge_verdict,
            "judge_reasoning": suggestion.judge_reasoning,
        },
        "original": {
            "title": suggestion.original_title,
            "date": suggestion.original_date,
            "correspondent_id": suggestion.original_correspondent,
            "correspondent_name": correspondent_lookup.get(suggestion.original_correspondent),
            "doctype_id": suggestion.original_doctype,
            "doctype_name": doctype_lookup.get(suggestion.original_doctype),
            "storage_path_id": suggestion.original_storage_path,
            "storage_path_name": storage_lookup.get(suggestion.original_storage_path),
            "tags": original_tags,
        },
        "proposed": {
            "title": suggestion.proposed_title or suggestion.original_title or "",
            "date": suggestion.effective_date,
            "correspondent_id": suggestion.effective_correspondent_id,
            "correspondent_name": correspondent_lookup.get(suggestion.effective_correspondent_id),
            "suggested_correspondent_name": (
                suggestion.proposed_correspondent_name
                if suggestion.proposed_correspondent_id is None
                else None
            ),
            "doctype_id": suggestion.effective_doctype_id,
            "doctype_name": doctype_lookup.get(suggestion.effective_doctype_id),
            "suggested_doctype_name": (
                suggestion.proposed_doctype_name if suggestion.proposed_doctype_id is None else None
            ),
            "storage_path_id": suggestion.effective_storage_path_id,
            "storage_path_name": storage_lookup.get(suggestion.effective_storage_path_id),
            "suggested_storage_path_name": (
                suggestion.proposed_storage_path_name
                if suggestion.proposed_storage_path_id is None
                else None
            ),
            "tags": proposed_tags,
        },
        "options": lookups,
        "context_docs": _json_list(suggestion.context_docs_json),
        "original_proposal": _json_object(suggestion.original_proposed_json),
    }


@router.post("/review/{suggestion_id}/save")
async def review_save_api(
    request: Request, suggestion_id: int, payload: Annotated[dict[str, Any] | None, Body()] = None
) -> dict[str, Any]:
    payload = payload or {}
    with get_conn() as conn:
        row = conn.execute("SELECT * FROM suggestions WHERE id = ?", (suggestion_id,)).fetchone()
    if not row:
        raise HTTPException(status_code=404, detail="Suggestion not found")

    suggestion = _row_to_suggestion(row)
    await _save_review_payload(request, suggestion, payload)
    return {"ok": True, "status": "saved", "message": "Änderungen gespeichert."}


@router.post("/review/{suggestion_id}/accept")
async def review_accept_api(
    request: Request, suggestion_id: int, payload: Annotated[dict[str, Any] | None, Body()] = None
) -> dict[str, Any]:
    payload = payload or {}
    with get_conn() as conn:
        row = conn.execute("SELECT * FROM suggestions WHERE id = ?", (suggestion_id,)).fetchone()
    if not row:
        raise HTTPException(status_code=404, detail="Suggestion not found")

    suggestion = _row_to_suggestion(row)
    suggestion = await _save_review_payload(request, suggestion, payload)
    decision = ReviewDecision(
        suggestion_id=suggestion.id,
        title=suggestion.proposed_title or suggestion.original_title or "",
        date=suggestion.effective_date,
        correspondent_id=suggestion.effective_correspondent_id,
        doctype_id=suggestion.effective_doctype_id,
        storage_path_id=suggestion.effective_storage_path_id,
        tag_ids=[tag_id for tag_id in _coerce_tag_ids([tag.get("id") for tag in _json_list(suggestion.proposed_tags_json) if isinstance(tag, dict)]) if tag_id],
        action="accept",
    )

    await commit_suggestion(suggestion, decision, request.app.state.paperless)
    with get_conn() as conn:
        updated = conn.execute("SELECT status FROM suggestions WHERE id = ?", (suggestion_id,)).fetchone()
    final_status = updated["status"] if updated else "error"
    if final_status != "committed":
        return {"ok": False, "status": final_status, "message": "Commit fehlgeschlagen. Vorschlag bleibt zur Prüfung offen."}

    return {"ok": True, "status": final_status, "message": "Vorschlag erfolgreich übernommen."}


@router.post("/review/{suggestion_id}/reject")
async def review_reject_api(
    suggestion_id: int, payload: Annotated[dict[str, Any] | None, Body()] = None
) -> dict[str, Any]:
    del payload
    with get_conn() as conn:
        row = conn.execute("SELECT document_id, status FROM suggestions WHERE id = ?", (suggestion_id,)).fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="Suggestion not found")
        conn.execute("UPDATE suggestions SET status = 'rejected' WHERE id = ?", (suggestion_id,))
        conn.execute(
            """
            UPDATE processed_documents
            SET status = 'rejected'
            WHERE document_id = ?
            """,
            (row["document_id"],),
        )
        conn.execute(
            """
            INSERT INTO audit_log (action, document_id, actor, details)
            VALUES ('reject', ?, 'user', NULL)
            """,
            (row["document_id"],),
        )

    return {"ok": True, "status": "rejected", "message": "Vorschlag verworfen."}


@router.get("/inbox")
async def inbox_api(limit: int = Query(default=100, ge=1, le=500)) -> dict[str, Any]:
    return get_inbox_snapshot(limit=limit)


@router.get("/tags")
async def tags_api() -> dict[str, Any]:
    return get_tags_snapshot()


@router.get("/stats")
async def stats_api() -> dict[str, Any]:
    return get_stats_snapshot()


@router.get("/embeddings")
async def embeddings_api(limit: int = Query(default=100, ge=1, le=500)) -> dict[str, Any]:
    return get_embeddings_snapshot(limit=limit)


@router.get("/chat")
async def chat_api(limit: int = Query(default=8, ge=1, le=100)) -> dict[str, Any]:
    return get_chat_snapshot(limit=limit)


@router.get("/settings/schema")
async def settings_schema_api() -> dict[str, Any]:
    return get_settings_schema()


@router.post("/settings")
async def save_settings_api(
    request: Request, payload: Annotated[dict[str, Any] | None, Body()] = None
):
    payload = payload or {}
    updates = payload.get("updates")
    if not isinstance(updates, dict) or not updates:
        raise HTTPException(status_code=400, detail="Missing updates payload")

    changed, restart_required = save_config(updates)
    runtime_fields = {k: v for k, v in changed.items() if k not in restart_required}
    actions: list[str] = []
    if runtime_fields:
        actions = await apply_runtime_changes(request.app, changed)

    return {
        "saved": bool(changed),
        "changed": changed,
        "restart_required": sorted(restart_required),
        "actions": actions,
    }
