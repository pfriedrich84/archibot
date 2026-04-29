"""JSON API routes used by the new SvelteKit admin frontend."""

from __future__ import annotations

import asyncio
import contextlib
import json
from typing import Annotated, Any

import structlog
from fastapi import APIRouter, Body, HTTPException, Query, Request
from fastapi.responses import StreamingResponse

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
from app.chat import ask as ask_chat
from app.chat import delete_chat_session, get_chat_session_snapshot, get_or_create_session
from app.config import settings
from app.config_writer import apply_runtime_changes, save_config
from app.db import get_conn, mark_setup_complete
from app.indexer import cancel_reindex, get_reindex_progress, start_reindex_task
from app.models import ReviewDecision, SuggestionRow
from app.pipeline.committer import commit_suggestion
from app.worker import cancel_poll, get_poll_progress, start_poll_task

router = APIRouter(prefix="/api/v1", tags=["api"])
log = structlog.get_logger(__name__)


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


def _coerce_name(payload: dict[str, Any], key: str = "name") -> str:
    value = str(payload.get(key) or "").strip()
    if not value:
        raise HTTPException(status_code=400, detail=f"Missing {key}")
    return value


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

    title = str(
        payload.get("title") or suggestion.proposed_title or suggestion.original_title or ""
    )
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
        {"id": tag_id, "name": tag_lookup.get(tag_id, f"Tag #{tag_id}")} for tag_id in tag_ids
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


async def _commit_review_suggestion(request: Request, suggestion: SuggestionRow) -> dict[str, Any]:
    decision = ReviewDecision(
        suggestion_id=suggestion.id,
        title=suggestion.proposed_title or suggestion.original_title or "",
        date=suggestion.effective_date,
        correspondent_id=suggestion.effective_correspondent_id,
        doctype_id=suggestion.effective_doctype_id,
        storage_path_id=suggestion.effective_storage_path_id,
        tag_ids=[
            tag_id
            for tag_id in _coerce_tag_ids(
                [
                    tag.get("id")
                    for tag in _json_list(suggestion.proposed_tags_json)
                    if isinstance(tag, dict)
                ]
            )
            if tag_id
        ],
        action="accept",
    )

    await commit_suggestion(suggestion, decision, request.app.state.paperless)
    with get_conn() as conn:
        updated = conn.execute(
            "SELECT status FROM suggestions WHERE id = ?", (suggestion.id,)
        ).fetchone()
    final_status = updated["status"] if updated else "error"
    return {
        "ok": final_status == "committed",
        "status": final_status,
        "message": (
            "Vorschlag erfolgreich übernommen."
            if final_status == "committed"
            else "Commit fehlgeschlagen. Vorschlag bleibt zur Prüfung offen."
        ),
    }


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
async def review_queue_api(
    request: Request,
    page: int = Query(default=1, ge=1),
    per_page: int = Query(default=25, ge=1, le=100),
    min_conf: int | None = Query(default=None, ge=0, le=100),
    max_conf: int | None = Query(default=None, ge=0, le=100),
    correspondent_id: int | None = Query(default=None, ge=1),
    judge_verdict: str | None = Query(default=None),
    sort: str = Query(default="created_desc"),
) -> dict[str, Any]:
    payload = get_review_queue(
        page=page,
        per_page=per_page,
        min_conf=min_conf,
        max_conf=max_conf,
        correspondent_id=correspondent_id,
        judge_verdict=judge_verdict,
        sort=sort,
    )
    payload["filters"] = {
        "correspondents": (await _load_review_lookups(request))["correspondents"],
    }
    return payload


# Bulk review routes must be registered before /review/{suggestion_id}.
@router.post("/review/bulk/accept")
async def review_bulk_accept_api(
    request: Request, payload: Annotated[dict[str, Any] | None, Body()] = None
) -> dict[str, Any]:
    payload = payload or {}
    suggestion_ids = list(dict.fromkeys(_coerce_tag_ids(payload.get("suggestion_ids"))))
    if not suggestion_ids:
        raise HTTPException(status_code=400, detail="Missing suggestion_ids")

    succeeded = 0
    failed = 0
    skipped = 0
    statuses: dict[str, str] = {}

    for suggestion_id in suggestion_ids:
        with get_conn() as conn:
            row = conn.execute(
                "SELECT * FROM suggestions WHERE id = ?", (suggestion_id,)
            ).fetchone()
        if not row or row["status"] != "pending":
            skipped += 1
            statuses[str(suggestion_id)] = "skipped"
            continue

        suggestion = _row_to_suggestion(row)
        result = await _commit_review_suggestion(request, suggestion)
        statuses[str(suggestion_id)] = result["status"]
        if result["ok"]:
            succeeded += 1
        else:
            failed += 1

    ok = failed == 0 and succeeded > 0
    parts: list[str] = []
    if succeeded:
        parts.append(f"{succeeded} übernommen")
    if failed:
        parts.append(f"{failed} fehlgeschlagen")
    if skipped:
        parts.append(f"{skipped} übersprungen")

    return {
        "ok": ok,
        "status": "committed" if ok else ("partial" if succeeded else "error"),
        "message": ", ".join(parts) or "Keine Änderungen",
        "succeeded": succeeded,
        "failed": failed,
        "skipped": skipped,
        "statuses": statuses,
    }


@router.post("/review/bulk/reject")
async def review_bulk_reject_api(
    payload: Annotated[dict[str, Any] | None, Body()] = None,
) -> dict[str, Any]:
    payload = payload or {}
    suggestion_ids = list(dict.fromkeys(_coerce_tag_ids(payload.get("suggestion_ids"))))
    if not suggestion_ids:
        raise HTTPException(status_code=400, detail="Missing suggestion_ids")

    rejected = 0
    skipped = 0
    statuses: dict[str, str] = {}

    for suggestion_id in suggestion_ids:
        with get_conn() as conn:
            row = conn.execute(
                "SELECT document_id, status FROM suggestions WHERE id = ?", (suggestion_id,)
            ).fetchone()
            if not row or row["status"] != "pending":
                skipped += 1
                statuses[str(suggestion_id)] = "skipped"
                continue
            conn.execute(
                "UPDATE suggestions SET status = 'rejected' WHERE id = ?", (suggestion_id,)
            )
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
        rejected += 1
        statuses[str(suggestion_id)] = "rejected"

    return {
        "ok": rejected > 0,
        "status": "rejected" if rejected > 0 else "skipped",
        "message": ", ".join(
            part
            for part in [
                f"{rejected} verworfen" if rejected else "",
                f"{skipped} übersprungen" if skipped else "",
            ]
            if part
        )
        or "Keine Änderungen",
        "succeeded": rejected,
        "failed": 0,
        "skipped": skipped,
        "statuses": statuses,
    }


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
        tag_name = (
            tag.get("name")
            or (tag_lookup.get(tag_id) if isinstance(tag_id, int) else None)
            or "Unbekannt"
        )
        proposed_tags.append({"id": tag_id, "name": tag_name, "confidence": tag.get("confidence")})

    with get_conn() as conn:
        queue_rows = conn.execute(
            """
            SELECT id
            FROM suggestions
            WHERE status = 'pending'
              AND id = (
                  SELECT MAX(s2.id)
                  FROM suggestions s2
                  WHERE s2.document_id = suggestions.document_id
                    AND s2.status = 'pending'
              )
            ORDER BY created_at DESC, id DESC
            """
        ).fetchall()
    queue_ids = [item["id"] for item in queue_rows]
    index = queue_ids.index(suggestion.id) if suggestion.id in queue_ids else -1
    prev_id = queue_ids[index - 1] if index > 0 else None
    next_id = queue_ids[index + 1] if index >= 0 and index + 1 < len(queue_ids) else None

    original_tag_ids = [
        tag_id for tag_id in _json_list(suggestion.original_tags_json) if isinstance(tag_id, int)
    ]
    effective_tag_ids = [
        tag.get("id") for tag in _json_list(suggestion.proposed_tags_json) if isinstance(tag, dict)
    ]
    effective_tag_ids = [tag_id for tag_id in effective_tag_ids if isinstance(tag_id, int)]
    changed_fields = {
        "title": (suggestion.proposed_title or suggestion.original_title or "")
        != (suggestion.original_title or ""),
        "date": (suggestion.effective_date or "") != (suggestion.original_date or ""),
        "correspondent": suggestion.effective_correspondent_id != suggestion.original_correspondent,
        "doctype": suggestion.effective_doctype_id != suggestion.original_doctype,
        "storage_path": suggestion.effective_storage_path_id != suggestion.original_storage_path,
        "tags": sorted(effective_tag_ids) != sorted(original_tag_ids),
    }

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
            "prev_id": prev_id,
            "next_id": next_id,
            "preview_url": f"/api/v1/review/{suggestion.id}/preview",
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
        "changed_fields": changed_fields,
    }


@router.get("/review/{suggestion_id}/preview")
async def review_preview_api(request: Request, suggestion_id: int):
    with get_conn() as conn:
        row = conn.execute(
            "SELECT document_id FROM suggestions WHERE id = ?", (suggestion_id,)
        ).fetchone()
    if not row:
        raise HTTPException(status_code=404, detail="Suggestion not found")

    content, content_type = await request.app.state.paperless.download_document(row["document_id"])
    return StreamingResponse(
        iter([content]),
        media_type=content_type or "application/octet-stream",
        headers={"Content-Disposition": f'inline; filename="document-{row["document_id"]}"'},
    )


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
    return await _commit_review_suggestion(request, suggestion)


@router.post("/review/{suggestion_id}/reject")
async def review_reject_api(
    suggestion_id: int, payload: Annotated[dict[str, Any] | None, Body()] = None
) -> dict[str, Any]:
    del payload
    with get_conn() as conn:
        row = conn.execute(
            "SELECT document_id, status FROM suggestions WHERE id = ?", (suggestion_id,)
        ).fetchone()
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


@router.post("/tags/approve")
async def approve_tag_api(
    request: Request, payload: Annotated[dict[str, Any] | None, Body()] = None
) -> dict[str, Any]:
    payload = payload or {}
    name = _coerce_name(payload)
    entity = await request.app.state.paperless.create_tag(name)
    with get_conn() as conn:
        conn.execute(
            "UPDATE tag_whitelist SET approved = 1, paperless_id = ? WHERE name = ?",
            (entity.id, name),
        )
    return {"ok": True, "status": "approved", "message": f"Tag '{name}' freigegeben."}


@router.post("/tags/reject")
async def reject_tag_api(
    payload: Annotated[dict[str, Any] | None, Body()] = None,
) -> dict[str, Any]:
    payload = payload or {}
    name = _coerce_name(payload)
    with get_conn() as conn:
        row = conn.execute(
            "SELECT times_seen FROM tag_whitelist WHERE name = ?", (name,)
        ).fetchone()
        times_seen = row["times_seen"] if row else 1
        conn.execute("DELETE FROM tag_whitelist WHERE name = ?", (name,))
        conn.execute(
            "INSERT OR REPLACE INTO tag_blacklist (name, times_seen) VALUES (?, ?)",
            (name, times_seen),
        )
    return {"ok": True, "status": "rejected", "message": f"Tag '{name}' blockiert."}


@router.post("/tags/unblacklist")
async def unblacklist_tag_api(
    payload: Annotated[dict[str, Any] | None, Body()] = None,
) -> dict[str, Any]:
    payload = payload or {}
    name = _coerce_name(payload)
    with get_conn() as conn:
        conn.execute("DELETE FROM tag_blacklist WHERE name = ?", (name,))
    return {"ok": True, "status": "restored", "message": f"Tag '{name}' wieder freigegeben."}


@router.post("/correspondents/approve")
async def approve_correspondent_api(
    request: Request, payload: Annotated[dict[str, Any] | None, Body()] = None
) -> dict[str, Any]:
    payload = payload or {}
    name = _coerce_name(payload)
    entity = await request.app.state.paperless.create_correspondent(name)
    with get_conn() as conn:
        conn.execute(
            "UPDATE correspondent_whitelist SET approved = 1, paperless_id = ? WHERE name = ?",
            (entity.id, name),
        )
    return {"ok": True, "status": "approved", "message": f"Korrespondent '{name}' freigegeben."}


@router.post("/correspondents/reject")
async def reject_correspondent_api(
    payload: Annotated[dict[str, Any] | None, Body()] = None,
) -> dict[str, Any]:
    payload = payload or {}
    name = _coerce_name(payload)
    with get_conn() as conn:
        row = conn.execute(
            "SELECT times_seen FROM correspondent_whitelist WHERE name = ?", (name,)
        ).fetchone()
        times_seen = row["times_seen"] if row else 1
        conn.execute("DELETE FROM correspondent_whitelist WHERE name = ?", (name,))
        conn.execute(
            "INSERT OR REPLACE INTO correspondent_blacklist (name, times_seen) VALUES (?, ?)",
            (name, times_seen),
        )
    return {"ok": True, "status": "rejected", "message": f"Korrespondent '{name}' blockiert."}


@router.post("/correspondents/unblacklist")
async def unblacklist_correspondent_api(
    payload: Annotated[dict[str, Any] | None, Body()] = None,
) -> dict[str, Any]:
    payload = payload or {}
    name = _coerce_name(payload)
    with get_conn() as conn:
        conn.execute("DELETE FROM correspondent_blacklist WHERE name = ?", (name,))
    return {
        "ok": True,
        "status": "restored",
        "message": f"Korrespondent '{name}' wieder freigegeben.",
    }


@router.post("/doctypes/approve")
async def approve_doctype_api(
    request: Request, payload: Annotated[dict[str, Any] | None, Body()] = None
) -> dict[str, Any]:
    payload = payload or {}
    name = _coerce_name(payload)
    entity = await request.app.state.paperless.create_document_type(name)
    with get_conn() as conn:
        conn.execute(
            "UPDATE doctype_whitelist SET approved = 1, paperless_id = ? WHERE name = ?",
            (entity.id, name),
        )
    return {"ok": True, "status": "approved", "message": f"Dokumenttyp '{name}' freigegeben."}


@router.post("/doctypes/reject")
async def reject_doctype_api(
    payload: Annotated[dict[str, Any] | None, Body()] = None,
) -> dict[str, Any]:
    payload = payload or {}
    name = _coerce_name(payload)
    with get_conn() as conn:
        row = conn.execute(
            "SELECT times_seen FROM doctype_whitelist WHERE name = ?", (name,)
        ).fetchone()
        times_seen = row["times_seen"] if row else 1
        conn.execute("DELETE FROM doctype_whitelist WHERE name = ?", (name,))
        conn.execute(
            "INSERT OR REPLACE INTO doctype_blacklist (name, times_seen) VALUES (?, ?)",
            (name, times_seen),
        )
    return {"ok": True, "status": "rejected", "message": f"Dokumenttyp '{name}' blockiert."}


@router.post("/doctypes/unblacklist")
async def unblacklist_doctype_api(
    payload: Annotated[dict[str, Any] | None, Body()] = None,
) -> dict[str, Any]:
    payload = payload or {}
    name = _coerce_name(payload)
    with get_conn() as conn:
        conn.execute("DELETE FROM doctype_blacklist WHERE name = ?", (name,))
    return {
        "ok": True,
        "status": "restored",
        "message": f"Dokumenttyp '{name}' wieder freigegeben.",
    }


@router.post("/jobs/poll/start")
async def start_poll_api() -> dict[str, Any]:
    started = start_poll_task()
    progress = get_poll_progress()
    if not started and not progress.running:
        raise HTTPException(status_code=409, detail="Poll konnte nicht gestartet werden.")
    return {
        "running": progress.running,
        "phase": progress.phase,
        "done": progress.done,
        "total": progress.total,
        "succeeded": progress.succeeded,
        "failed": progress.failed,
        "skipped": progress.skipped,
        "cancelled": progress.cancelled,
        "error": progress.error,
        "started_at": progress.started_at,
        "last_poll": None,
        "next_run_at": None,
    }


@router.post("/jobs/poll/cancel")
async def cancel_poll_api() -> dict[str, Any]:
    cancel_poll()
    progress = get_poll_progress()
    return {
        "running": progress.running,
        "phase": progress.phase,
        "done": progress.done,
        "total": progress.total,
        "succeeded": progress.succeeded,
        "failed": progress.failed,
        "skipped": progress.skipped,
        "cancelled": progress.cancelled,
        "error": progress.error,
        "started_at": progress.started_at,
        "last_poll": None,
        "next_run_at": None,
    }


@router.post("/jobs/reindex/start")
async def start_reindex_api(request: Request) -> dict[str, Any]:
    started = start_reindex_task(request.app.state.paperless, request.app.state.ollama)
    progress = get_reindex_progress()
    if not started and not progress.running:
        raise HTTPException(status_code=409, detail="Reindex konnte nicht gestartet werden.")
    return {
        "running": progress.running,
        "done": progress.done,
        "total": progress.total,
        "failed": progress.failed,
        "cancelled": progress.cancelled,
        "error": progress.error,
        "started_at": progress.started_at,
        "finished_at": progress.finished_at,
    }


@router.post("/jobs/reindex/cancel")
async def cancel_reindex_api() -> dict[str, Any]:
    cancel_reindex()
    progress = get_reindex_progress()
    return {
        "running": progress.running,
        "done": progress.done,
        "total": progress.total,
        "failed": progress.failed,
        "cancelled": progress.cancelled,
        "error": progress.error,
        "started_at": progress.started_at,
        "finished_at": progress.finished_at,
    }


@router.get("/stats")
async def stats_api() -> dict[str, Any]:
    return get_stats_snapshot()


@router.get("/embeddings")
async def embeddings_api(limit: int = Query(default=100, ge=1, le=500)) -> dict[str, Any]:
    return get_embeddings_snapshot(limit=limit)


@router.get("/chat")
async def chat_api(limit: int = Query(default=8, ge=1, le=100)) -> dict[str, Any]:
    return get_chat_snapshot(limit=limit)


@router.get("/chat/sessions/{session_id}")
async def chat_session_api(session_id: str) -> dict[str, Any]:
    session = get_chat_session_snapshot(session_id)
    if session is None:
        raise HTTPException(status_code=404, detail="Chat session not found")
    return session


@router.delete("/chat/sessions/{session_id}")
async def chat_delete_session_api(session_id: str) -> dict[str, Any]:
    deleted = delete_chat_session(session_id)
    if not deleted:
        raise HTTPException(status_code=404, detail="Chat session not found")
    return {"deleted": True}


@router.post("/chat/ask")
async def chat_ask_api(
    request: Request, payload: Annotated[dict[str, Any], Body(...)]
) -> dict[str, Any]:
    question = str(payload.get("question") or "").strip()
    if not question:
        raise HTTPException(status_code=400, detail="Missing question")

    session_id, session = get_or_create_session(
        str(payload.get("session_id") or "").strip() or None
    )
    result = await ask_chat(
        question,
        session,
        request.app.state.paperless,
        request.app.state.ollama,
    )
    return {
        "session_id": session_id,
        "answer": result.answer,
        "sources": result.sources,
    }


@router.get("/settings/schema")
async def settings_schema_api() -> dict[str, Any]:
    return get_settings_schema()


@router.get("/paperless/tags")
async def paperless_tags_api(request: Request) -> dict[str, Any]:
    paperless = getattr(request.app.state, "paperless", None)
    if paperless is None:
        return {"items": []}
    tags = await paperless.list_tags()
    return {"items": [{"id": tag.id, "name": tag.name} for tag in tags]}


@router.post("/paperless/test")
async def paperless_test_api(
    payload: Annotated[dict[str, Any] | None, Body()] = None,
) -> dict[str, Any]:
    from app.clients.paperless import PaperlessClient

    payload = payload or {}
    base_url = str(payload.get("paperless_url") or settings.paperless_url or "").strip()
    token = str(payload.get("paperless_token") or settings.paperless_token or "").strip()
    if not base_url or not token:
        raise HTTPException(status_code=400, detail="Paperless URL and token are required")

    client = PaperlessClient(base_url=base_url, token=token)
    try:
        tags = await client.list_tags()
    except Exception as exc:
        log.warning("paperless setup test failed", error=str(exc), url=base_url)
        return {"ok": False, "items": [], "error": str(exc)}
    finally:
        await client.aclose()

    return {"ok": True, "items": [{"id": tag.id, "name": tag.name} for tag in tags]}


@router.get("/ollama/models")
async def ollama_models_api(request: Request) -> dict[str, Any]:
    ollama = getattr(request.app.state, "ollama", None)
    if ollama is None:
        return {"items": []}
    models = await ollama.list_models()
    return {"items": [{"name": name} for name in models]}


def _write_settings_error(stage: str, message: str) -> None:
    with get_conn() as conn:
        conn.execute(
            "INSERT INTO errors (stage, document_id, message) VALUES (?, ?, ?)",
            (stage, None, message),
        )


@router.post("/settings")
async def save_settings_api(
    request: Request, payload: Annotated[dict[str, Any] | None, Body()] = None
):
    payload = payload or {}
    updates = payload.get("updates")
    if not isinstance(updates, dict) or not updates:
        raise HTTPException(status_code=400, detail="Missing updates payload")

    changed, restart_required = save_config(updates)
    field_errors: dict[str, str] = {}
    tag_fields = {
        "paperless_inbox_tag_id": "Configured inbox tag ID {tag_id} does not exist in Paperless",
        "paperless_processed_tag_id": "Configured processed tag ID {tag_id} does not exist in Paperless",
        "ocr_requested_tag_id": "Configured OCR tag ID {tag_id} does not exist in Paperless",
    }
    changed_tag_fields = [
        field for field in tag_fields if field in updates and int(getattr(settings, field) or 0) > 0
    ]
    if changed_tag_fields:
        tags = await request.app.state.paperless.list_tags()
        tag_ids = {tag.id for tag in tags}
        for field in changed_tag_fields:
            tag_id = int(getattr(settings, field) or 0)
            if tag_id not in tag_ids:
                message = tag_fields[field].format(tag_id=tag_id)
                field_errors[field] = message
                _write_settings_error("paperless_config", message)

    runtime_fields = {k: v for k, v in changed.items() if k not in restart_required}
    actions: list[str] = []
    if runtime_fields:
        actions = await apply_runtime_changes(request.app, changed)

    if settings.paperless_url and settings.paperless_token and settings.paperless_inbox_tag_id != 0:
        mark_setup_complete()

    return {
        "saved": bool(changed),
        "changed": changed,
        "restart_required": sorted(restart_required),
        "actions": actions,
        "field_errors": field_errors,
    }
