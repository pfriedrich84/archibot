"""Review queue — list, detail, accept, reject, edit suggestions."""

from __future__ import annotations

import contextlib
import json

import structlog
from fastapi import APIRouter, Form, Request
from fastapi.responses import HTMLResponse, RedirectResponse

from app.db import get_conn
from app.models import ReviewDecision, SuggestionRow
from app.pipeline.committer import commit_suggestion

log = structlog.get_logger(__name__)
router = APIRouter(prefix="/review")


def _row_to_suggestion(row) -> SuggestionRow:
    return SuggestionRow(**dict(row))


def _decision_from_proposed(suggestion: SuggestionRow) -> ReviewDecision:
    """Build a ReviewDecision from a suggestion's proposed values (no editing)."""
    tag_ids: list[int] = []
    if suggestion.proposed_tags_json:
        with contextlib.suppress(json.JSONDecodeError):
            for t in json.loads(suggestion.proposed_tags_json):
                tid = t.get("id")
                if tid is not None:
                    tag_ids.append(tid)
    return ReviewDecision(
        suggestion_id=suggestion.id,
        title=suggestion.proposed_title or "",
        date=suggestion.effective_date,
        correspondent_id=suggestion.effective_correspondent_id,
        doctype_id=suggestion.effective_doctype_id,
        storage_path_id=suggestion.effective_storage_path_id,
        tag_ids=tag_ids,
        action="accept",
    )


@router.get("")
async def review_list(request: Request):
    return RedirectResponse(url="/app/review", status_code=302)


@router.get("/{suggestion_id}")
async def review_detail(request: Request, suggestion_id: int):
    with get_conn() as conn:
        row = conn.execute("SELECT 1 FROM suggestions WHERE id = ?", (suggestion_id,)).fetchone()
    if not row:
        return HTMLResponse("Suggestion not found", status_code=404)
    return RedirectResponse(url="/app/review", status_code=302)


@router.post("/{suggestion_id}/accept")
async def accept_suggestion(
    request: Request,
    suggestion_id: int,
    title: str = Form(...),
    date: str = Form(""),
    correspondent_id: str = Form(""),
    doctype_id: str = Form(""),
    storage_path_id: str = Form(""),
    tag_ids: list[str] = Form(default=[]),  # noqa: B008
):
    with get_conn() as conn:
        row = conn.execute("SELECT * FROM suggestions WHERE id = ?", (suggestion_id,)).fetchone()
    if not row:
        return HTMLResponse("Suggestion not found", status_code=404)

    suggestion = _row_to_suggestion(row)
    decision = ReviewDecision(
        suggestion_id=suggestion_id,
        title=title,
        date=date or None,
        correspondent_id=int(correspondent_id) if correspondent_id else None,
        doctype_id=int(doctype_id) if doctype_id else None,
        storage_path_id=int(storage_path_id) if storage_path_id else None,
        tag_ids=[int(t) for t in tag_ids if t],
        action="accept",
    )

    paperless = request.app.state.paperless
    log.info("accepting suggestion", suggestion_id=suggestion_id, doc_id=suggestion.document_id)
    await commit_suggestion(suggestion, decision, paperless)

    # Return HTMX partial — empty row signals removal
    return HTMLResponse(
        f'<tr id="suggestion-{suggestion_id}" class="bg-green-50">'
        f'<td colspan="5" class="px-4 py-3 text-green-700 text-center">'
        f"Committed successfully</td></tr>"
    )


@router.post("/{suggestion_id}/reject")
async def reject_suggestion(request: Request, suggestion_id: int):
    log.info("rejecting suggestion", suggestion_id=suggestion_id)
    with get_conn() as conn:
        conn.execute(
            "UPDATE suggestions SET status = 'rejected' WHERE id = ?",
            (suggestion_id,),
        )
        conn.execute(
            """
            UPDATE processed_documents
            SET status = 'rejected'
            WHERE document_id = (SELECT document_id FROM suggestions WHERE id = ?)
            """,
            (suggestion_id,),
        )
        conn.execute(
            """
            INSERT INTO audit_log (action, document_id, actor, details)
            SELECT 'reject', document_id, 'user', NULL
            FROM suggestions WHERE id = ?
            """,
            (suggestion_id,),
        )

    return HTMLResponse(
        f'<tr id="suggestion-{suggestion_id}" class="bg-red-50">'
        f'<td colspan="5" class="px-4 py-3 text-red-700 text-center">'
        f"Rejected</td></tr>"
    )


@router.post("/{suggestion_id}/edit")
async def edit_suggestion(
    request: Request,
    suggestion_id: int,
    title: str = Form(...),
    date: str = Form(""),
    correspondent_id: str = Form(""),
    doctype_id: str = Form(""),
    storage_path_id: str = Form(""),
    tag_ids: list[str] = Form(default=[]),  # noqa: B008
):
    """Save edited fields without committing to Paperless."""
    log.info("editing suggestion", suggestion_id=suggestion_id)
    tag_dicts = [{"id": int(t)} for t in tag_ids if t]
    with get_conn() as conn:
        conn.execute(
            """
            UPDATE suggestions SET
                proposed_title = ?,
                proposed_date = ?,
                proposed_correspondent_id = ?,
                proposed_doctype_id = ?,
                proposed_storage_path_id = ?,
                proposed_tags_json = ?
            WHERE id = ?
            """,
            (
                title,
                date or None,
                int(correspondent_id) if correspondent_id else None,
                int(doctype_id) if doctype_id else None,
                int(storage_path_id) if storage_path_id else None,
                json.dumps(tag_dicts, ensure_ascii=False),
                suggestion_id,
            ),
        )

    return HTMLResponse('<div class="text-green-700 text-sm mt-2">Saved</div>')


def _bulk_oob_fragments(sid: int, css_bg: str, css_text: str, label: str) -> str:
    """Return OOB swap fragments for both desktop row and mobile card."""
    desktop = (
        f'<tr id="suggestion-{sid}" hx-swap-oob="true" class="{css_bg}">'
        f'<td colspan="5" class="px-4 py-3 {css_text} text-center">{label}</td></tr>'
    )
    mobile = (
        f'<div id="suggestion-m-{sid}" hx-swap-oob="true"'
        f' class="{css_bg} rounded-xl border p-4">'
        f'<p class="{css_text} text-center text-sm font-medium">{label}</p></div>'
    )
    return desktop + mobile


@router.post("/bulk-approve")
async def bulk_approve(request: Request):
    form = await request.form()
    raw_ids = form.getlist("suggestion_ids")
    ids = list(dict.fromkeys(int(x) for x in raw_ids if str(x).isdigit()))

    if not ids:
        resp = HTMLResponse('<div id="bulk-result"></div>')
        resp.headers["HX-Trigger"] = json.dumps(
            {"showToast": {"message": "No suggestions selected", "type": "error"}}
        )
        return resp

    paperless = request.app.state.paperless
    succeeded, failed, skipped = 0, 0, 0
    oob_parts: list[str] = []

    for sid in ids:
        with get_conn() as conn:
            row = conn.execute("SELECT * FROM suggestions WHERE id = ?", (sid,)).fetchone()
        if not row or row["status"] != "pending":
            skipped += 1
            continue

        suggestion = _row_to_suggestion(row)
        decision = _decision_from_proposed(suggestion)
        log.info("bulk-approving suggestion", suggestion_id=sid, doc_id=suggestion.document_id)
        await commit_suggestion(suggestion, decision, paperless)

        # Re-read status — commit_suggestion swallows errors and sets status='error'
        with get_conn() as conn:
            updated = conn.execute("SELECT status FROM suggestions WHERE id = ?", (sid,)).fetchone()
        final_status = updated["status"] if updated else "error"

        if final_status == "committed":
            succeeded += 1
            oob_parts.append(_bulk_oob_fragments(sid, "bg-green-50", "text-green-700", "Committed"))
        else:
            failed += 1
            oob_parts.append(_bulk_oob_fragments(sid, "bg-red-50", "text-red-700", "Error"))

    parts: list[str] = []
    if succeeded:
        parts.append(f"{succeeded} approved")
    if failed:
        parts.append(f"{failed} failed")
    if skipped:
        parts.append(f"{skipped} skipped")
    toast_type = "success" if failed == 0 else "error"

    resp = HTMLResponse('<div id="bulk-result"></div>' + "".join(oob_parts))
    resp.headers["HX-Trigger"] = json.dumps(
        {"showToast": {"message": ", ".join(parts), "type": toast_type}}
    )
    return resp


@router.post("/bulk-reject")
async def bulk_reject(request: Request):
    form = await request.form()
    raw_ids = form.getlist("suggestion_ids")
    ids = list(dict.fromkeys(int(x) for x in raw_ids if str(x).isdigit()))

    if not ids:
        resp = HTMLResponse('<div id="bulk-result"></div>')
        resp.headers["HX-Trigger"] = json.dumps(
            {"showToast": {"message": "No suggestions selected", "type": "error"}}
        )
        return resp

    rejected, skipped = 0, 0
    oob_parts: list[str] = []

    for sid in ids:
        with get_conn() as conn:
            row = conn.execute("SELECT * FROM suggestions WHERE id = ?", (sid,)).fetchone()
        if not row or row["status"] != "pending":
            skipped += 1
            continue

        log.info("bulk-rejecting suggestion", suggestion_id=sid)
        with get_conn() as conn:
            conn.execute("UPDATE suggestions SET status = 'rejected' WHERE id = ?", (sid,))
            conn.execute(
                """
                UPDATE processed_documents
                SET status = 'rejected'
                WHERE document_id = (SELECT document_id FROM suggestions WHERE id = ?)
                """,
                (sid,),
            )
            conn.execute(
                """INSERT INTO audit_log (action, document_id, actor, details)
                   SELECT 'reject', document_id, 'user', NULL
                   FROM suggestions WHERE id = ?""",
                (sid,),
            )
        rejected += 1
        oob_parts.append(_bulk_oob_fragments(sid, "bg-red-50", "text-red-700", "Rejected"))

    parts: list[str] = []
    if rejected:
        parts.append(f"{rejected} rejected")
    if skipped:
        parts.append(f"{skipped} skipped")

    resp = HTMLResponse('<div id="bulk-result"></div>' + "".join(oob_parts))
    resp.headers["HX-Trigger"] = json.dumps(
        {"showToast": {"message": ", ".join(parts), "type": "success"}}
    )
    return resp
