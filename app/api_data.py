"""Shared JSON-facing data builders for the new SvelteKit frontend."""

from __future__ import annotations

import json
from collections import OrderedDict
from datetime import UTC, datetime
from typing import Any

from app import db
from app.config import FIELD_META, needs_setup, settings
from app.indexer import get_reindex_progress
from app.worker import _has_embedding_index, get_poll_progress


def _parse_datetime(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        when = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None
    if when.tzinfo is None:
        when = when.replace(tzinfo=UTC)
    return when.astimezone(UTC)


def _next_poll_run(app: Any) -> str | None:
    scheduler = getattr(app.state, "scheduler", None)
    if not scheduler:
        return None
    job = scheduler.get_job("poll_inbox")
    if job and job.next_run_time:
        return job.next_run_time.isoformat()
    return None


def _last_poll() -> dict[str, Any] | None:
    with db.get_conn() as conn:
        row = conn.execute(
            """
            SELECT started_at, finished_at, total_docs, succeeded, failed, skipped
            FROM poll_cycles
            WHERE finished_at IS NOT NULL
            ORDER BY finished_at DESC
            LIMIT 1
            """
        ).fetchone()
    return dict(row) if row else None


def _phase_health() -> dict[str, dict[str, Any]]:
    with db.get_conn() as conn:
        rows = conn.execute(
            """
            SELECT phase,
                   COUNT(*) AS total,
                   SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS errors,
                   ROUND(AVG(duration_ms)) AS avg_ms
            FROM phase_timing
            WHERE started_at >= datetime('now', '-30 days')
            GROUP BY phase
            ORDER BY phase
            """
        ).fetchall()
    result: dict[str, dict[str, Any]] = {}
    for row in rows:
        total = row["total"] or 0
        errors = row["errors"] or 0
        result[row["phase"]] = {
            "total": total,
            "errors": errors,
            "avg_ms": row["avg_ms"] or 0,
            "error_rate_pct": round(errors / total * 100, 1) if total else 0.0,
        }
    return result


def _status_counts() -> dict[str, int]:
    with db.get_conn() as conn:
        rows = conn.execute(
            "SELECT status, COUNT(*) AS c FROM suggestions GROUP BY status ORDER BY status"
        ).fetchall()
    return {row["status"]: row["c"] for row in rows}


def _daily_commits() -> list[dict[str, Any]]:
    with db.get_conn() as conn:
        rows = conn.execute(
            """
            SELECT date(occurred_at) AS day, COUNT(*) AS c
            FROM audit_log
            WHERE action = 'commit' AND occurred_at >= date('now', '-7 days')
            GROUP BY day
            ORDER BY day
            """
        ).fetchall()
    return [{"day": row["day"], "count": row["c"]} for row in rows]


def get_recent_errors(limit: int = 10) -> list[dict[str, Any]]:
    with db.get_conn() as conn:
        rows = conn.execute(
            """
            SELECT id, occurred_at, stage, document_id, message, details
            FROM errors
            ORDER BY occurred_at DESC, id DESC
            LIMIT ?
            """,
            (limit,),
        ).fetchall()
    return [dict(row) for row in rows]


def get_review_queue(
    *,
    page: int = 1,
    per_page: int = 25,
    min_conf: int | None = None,
    max_conf: int | None = None,
    correspondent_id: int | None = None,
    judge_verdict: str | None = None,
    sort: str = "created_desc",
) -> dict[str, Any]:
    page = max(page, 1)
    per_page = max(1, min(per_page, 100))
    sort_map = {
        "created_desc": "s.created_at DESC, s.id DESC",
        "confidence_asc": "COALESCE(s.confidence, -1) ASC, s.created_at DESC, s.id DESC",
        "confidence_desc": "COALESCE(s.confidence, -1) DESC, s.created_at DESC, s.id DESC",
    }
    order_by = sort_map.get(sort, sort_map["created_desc"])

    filters = [
        "s.status = 'pending'",
        "s.id = (SELECT MAX(s2.id) FROM suggestions s2 WHERE s2.document_id = s.document_id AND s2.status = 'pending')",
    ]
    params: list[Any] = []

    if min_conf is not None:
        filters.append("COALESCE(s.confidence, 0) >= ?")
        params.append(min_conf)
    if max_conf is not None:
        filters.append("COALESCE(s.confidence, 0) <= ?")
        params.append(max_conf)
    if correspondent_id is not None:
        filters.append("COALESCE(s.proposed_correspondent_id, s.original_correspondent) = ?")
        params.append(correspondent_id)
    if judge_verdict:
        filters.append("s.judge_verdict = ?")
        params.append(judge_verdict)

    where_clause = " AND ".join(filters)
    offset = (page - 1) * per_page

    with db.get_conn() as conn:
        total = conn.execute(
            f"""
            SELECT COUNT(*) AS c
            FROM suggestions s
            WHERE {where_clause}
            """,
            params,
        ).fetchone()["c"]
        rows = conn.execute(
            f"""
            SELECT s.id,
                   s.document_id,
                   s.created_at,
                   s.status,
                   s.confidence,
                   s.proposed_title,
                   s.proposed_correspondent_name,
                   s.proposed_doctype_name,
                   s.proposed_storage_path_name,
                   s.judge_verdict,
                   COALESCE(s.proposed_correspondent_id, s.original_correspondent) AS effective_correspondent_id,
                   pd.status AS document_status
            FROM suggestions s
            LEFT JOIN processed_documents pd ON pd.document_id = s.document_id
            WHERE {where_clause}
            ORDER BY {order_by}
            LIMIT ? OFFSET ?
            """,
            [*params, per_page, offset],
        ).fetchall()

    total_pages = max((total + per_page - 1) // per_page, 1) if total else 1
    return {
        "items": [dict(row) for row in rows],
        "total": total,
        "page": page,
        "per_page": per_page,
        "total_pages": total_pages,
    }


def get_inbox_snapshot(limit: int = 100) -> dict[str, Any]:
    with db.get_conn() as conn:
        rows = conn.execute(
            """
            SELECT pd.document_id,
                   pd.status,
                   pd.last_updated_at,
                   pd.last_processed,
                   s.id AS suggestion_id,
                   s.status AS suggestion_status,
                   s.confidence,
                   s.proposed_title,
                   s.proposed_correspondent_name,
                   s.proposed_doctype_name
            FROM processed_documents pd
            LEFT JOIN suggestions s ON s.id = (
                SELECT s2.id
                FROM suggestions s2
                WHERE s2.document_id = pd.document_id
                ORDER BY s2.created_at DESC, s2.id DESC
                LIMIT 1
            )
            ORDER BY pd.last_processed DESC, pd.document_id DESC
            LIMIT ?
            """,
            (limit,),
        ).fetchall()

    counts: dict[str, int] = {}
    for row in rows:
        counts[row["status"]] = counts.get(row["status"], 0) + 1

    return {"items": [dict(row) for row in rows], "counts": counts, "total": len(rows)}


def _approval_snapshot(kind: str) -> dict[str, Any]:
    with db.get_conn() as conn:
        whitelist_rows = conn.execute(
            f"SELECT name, paperless_id, approved, first_seen, times_seen, notes FROM {kind}_whitelist ORDER BY approved ASC, times_seen DESC, name ASC"
        ).fetchall()
        blacklist_rows = conn.execute(
            f"SELECT name, rejected_at, times_seen, notes FROM {kind}_blacklist ORDER BY rejected_at DESC, name ASC"
        ).fetchall()
    return {
        "whitelist": [{**dict(row), "approved": bool(row["approved"])} for row in whitelist_rows],
        "blacklist": [dict(row) for row in blacklist_rows],
    }


def get_tags_snapshot() -> dict[str, Any]:
    return {
        "tags": _approval_snapshot("tag"),
        "correspondents": _approval_snapshot("correspondent"),
        "doctypes": _approval_snapshot("doctype"),
    }


def get_embeddings_snapshot(
    limit: int = 100,
    *,
    correspondent_names: dict[int, str] | None = None,
    doctype_names: dict[int, str] | None = None,
    storage_path_names: dict[int, str] | None = None,
    tag_names: dict[int, str] | None = None,
) -> dict[str, Any]:
    correspondent_names = correspondent_names or {}
    doctype_names = doctype_names or {}
    storage_path_names = storage_path_names or {}
    tag_names = tag_names or {}

    with db.get_conn() as conn:
        total = conn.execute("SELECT COUNT(*) AS c FROM doc_embedding_meta").fetchone()["c"]
        rows = conn.execute(
            """
            SELECT document_id, title, correspondent, doctype, storage_path, tags_json, created_date, indexed_at
            FROM doc_embedding_meta
            ORDER BY indexed_at DESC, document_id DESC
            LIMIT ?
            """,
            (limit,),
        ).fetchall()
    items = []
    for row in rows:
        item = dict(row)
        try:
            tags = json.loads(item.pop("tags_json") or "[]")
        except json.JSONDecodeError:
            tags = []
        tag_ids = tags if isinstance(tags, list) else []
        item["correspondent_name"] = correspondent_names.get(item["correspondent"])
        item["doctype_name"] = doctype_names.get(item["doctype"])
        item["storage_path_name"] = storage_path_names.get(item["storage_path"])
        item["tags"] = [tag_names.get(tag_id, str(tag_id)) for tag_id in tag_ids]
        items.append(item)
    return {"total_embedded": total, "items": items}


def get_stats_snapshot() -> dict[str, Any]:
    with db.get_conn() as conn:
        total_docs = conn.execute("SELECT COUNT(*) AS c FROM processed_documents").fetchone()["c"]
        total_errors = conn.execute("SELECT COUNT(*) AS c FROM errors").fetchone()["c"]
        embedded = conn.execute("SELECT COUNT(*) AS c FROM doc_embedding_meta").fetchone()["c"]

        auto_row = conn.execute(
            """
            SELECT
                SUM(CASE WHEN actor = 'auto' THEN 1 ELSE 0 END) AS auto_count,
                COUNT(*) AS total_count
            FROM audit_log
            WHERE action = 'commit'
            """
        ).fetchone()
        auto_commits = auto_row["auto_count"] or 0 if auto_row else 0
        total_commits = auto_row["total_count"] or 0 if auto_row else 0

        confidence_rows = conn.execute(
            """
            SELECT
                CASE
                    WHEN confidence IS NULL THEN 'unscored'
                    WHEN confidence < 20 THEN '0-19'
                    WHEN confidence < 40 THEN '20-39'
                    WHEN confidence < 60 THEN '40-59'
                    WHEN confidence < 80 THEN '60-79'
                    ELSE '80-100'
                END AS bucket,
                COUNT(*) AS c
            FROM suggestions
            GROUP BY bucket
            ORDER BY bucket
            """
        ).fetchall()

        judge_rows = conn.execute(
            """
            SELECT judge_verdict AS verdict, COUNT(*) AS c
            FROM suggestions
            WHERE judge_verdict IS NOT NULL
            GROUP BY judge_verdict
            ORDER BY judge_verdict
            """
        ).fetchall()

    return {
        "totals": {
            "processed_documents": total_docs,
            "embedded_documents": embedded,
            "total_errors": total_errors,
            "total_commits": total_commits,
            "auto_commits": auto_commits,
        },
        "status_counts": _status_counts(),
        "daily_commits": _daily_commits(),
        "phase_health": _phase_health(),
        "confidence_distribution": {row["bucket"]: row["c"] for row in confidence_rows},
        "judge_counts": {row["verdict"]: row["c"] for row in judge_rows},
    }


def get_chat_snapshot(limit: int = 8) -> dict[str, Any]:
    from app.chat import list_chat_sessions

    with db.get_conn() as conn:
        rows = conn.execute(
            """
            SELECT details, occurred_at
            FROM audit_log
            WHERE action IN ('commit', 'retry', 'reject')
            ORDER BY occurred_at DESC, id DESC
            LIMIT ?
            """,
            (limit,),
        ).fetchall()
    return {
        "sessions": list_chat_sessions(limit=limit),
        "recent_activity": [dict(row) for row in rows],
    }


def get_dashboard_snapshot(app: Any) -> dict[str, Any]:
    now = datetime.now(tz=UTC)
    with db.get_conn() as conn:
        pending_review = conn.execute(
            "SELECT COUNT(DISTINCT document_id) AS c FROM suggestions WHERE status = 'pending'"
        ).fetchone()["c"]
        committed_today = conn.execute(
            """
            SELECT COUNT(DISTINCT document_id) AS c FROM audit_log
            WHERE action = 'commit' AND occurred_at >= date('now')
            """
        ).fetchone()["c"]
        errors_24h = conn.execute(
            """
            SELECT COUNT(*) AS c FROM errors
            WHERE occurred_at >= datetime('now', '-24 hours')
            """
        ).fetchone()["c"]
        pending_tags = conn.execute(
            "SELECT COUNT(*) AS c FROM tag_whitelist WHERE approved = 0"
        ).fetchone()["c"]
        pending_correspondents = conn.execute(
            "SELECT COUNT(*) AS c FROM correspondent_whitelist WHERE approved = 0"
        ).fetchone()["c"]
        pending_doctypes = conn.execute(
            "SELECT COUNT(*) AS c FROM doctype_whitelist WHERE approved = 0"
        ).fetchone()["c"]
        total_docs = conn.execute("SELECT COUNT(*) AS c FROM processed_documents").fetchone()["c"]
        embedded = conn.execute("SELECT COUNT(*) AS c FROM doc_embedding_meta").fetchone()["c"]
        inbox_pending = conn.execute(
            "SELECT COUNT(*) AS c FROM processed_documents WHERE status = 'pending'"
        ).fetchone()["c"]

    poll = get_poll_progress()
    reindex = get_reindex_progress()
    last_poll = _last_poll()
    next_run = _next_poll_run(app)

    def _relative_time(value: str | None) -> str | None:
        when = _parse_datetime(value)
        if when is None:
            return None
        delta = max(int((now - when).total_seconds()), 0)
        if delta < 60:
            return f"{delta}s ago"
        if delta < 3600:
            return f"{delta // 60}m ago"
        return f"{delta // 3600}h {(delta % 3600) // 60}m ago"

    return {
        "generated_at": now.isoformat(),
        "kpis": {
            "pending_review": pending_review,
            "committed_today": committed_today,
            "errors_24h": errors_24h,
            "pending_tags": pending_tags,
            "pending_correspondents": pending_correspondents,
            "pending_doctypes": pending_doctypes,
            "processed_documents": total_docs,
            "embedded_documents": embedded,
            "inbox_pending": inbox_pending,
        },
        "status_counts": _status_counts(),
        "activity": {
            "daily_commits": _daily_commits(),
            "phase_health": _phase_health(),
        },
        "pipeline": {
            "running": poll.running,
            "phase": poll.phase,
            "done": poll.done,
            "total": poll.total,
            "succeeded": poll.succeeded,
            "failed": poll.failed,
            "skipped": poll.skipped,
            "cancelled": poll.cancelled,
            "error": poll.error,
            "started_at": poll.started_at,
            "last_poll": {
                **last_poll,
                "relative_finished": _relative_time(last_poll.get("finished_at"))
                if last_poll
                else None,
            }
            if last_poll
            else None,
            "next_run_at": next_run,
        },
        "reindex": {
            "running": reindex.running,
            "done": reindex.done,
            "total": reindex.total,
            "failed": reindex.failed,
            "cancelled": reindex.cancelled,
            "error": reindex.error,
            "started_at": reindex.started_at,
            "finished_at": reindex.finished_at,
        },
        "health": {
            "setup_complete": not needs_setup(),
            "embedding_index_ready": _has_embedding_index(),
            "paperless_configured": bool(settings.paperless_url and settings.paperless_token),
            "ollama_configured": bool(settings.ollama_url),
            "ocr_mode": settings.ocr_mode,
            "poll_interval_seconds": settings.poll_interval_seconds,
            "auto_commit_confidence": settings.auto_commit_confidence,
        },
        "recent_errors": get_recent_errors(limit=8),
    }


def get_system_status(app: Any) -> dict[str, Any]:
    return {
        "app": {
            "name": "ArchiBot",
            "version": getattr(app, "version", "0.1.0"),
            "setup_complete": not needs_setup(),
            "legacy_ui": {
                "active": False,
                "deprecated": True,
                "cutover_ready": True,
            },
            "frontend": {
                "new_app_path": "/app",
                "mode": "migration",
                "rendering": "hybrid",
            },
        },
        "services": {
            "paperless": {
                "configured": bool(settings.paperless_url and settings.paperless_token),
                "url": settings.paperless_url,
            },
            "ollama": {
                "configured": bool(settings.ollama_url),
                "url": settings.ollama_url,
                "model": settings.ollama_model,
                "ocr_model": settings.ollama_ocr_model,
                "embedding_model": settings.ollama_embed_model,
            },
        },
        "jobs": {
            "poll": get_dashboard_snapshot(app)["pipeline"],
            "reindex": get_dashboard_snapshot(app)["reindex"],
        },
        "logging": {
            "level": settings.log_level,
            "request_ids": True,
            "structured_logs": settings.log_level.upper() != "DEBUG",
        },
    }


def get_settings_schema() -> dict[str, Any]:
    groups: OrderedDict[str, list[dict[str, Any]]] = OrderedDict()
    for field_name, meta in FIELD_META.items():
        category = meta["category"]
        value = getattr(settings, field_name, "")
        if category not in groups:
            groups[category] = []
        field = {
            "name": field_name,
            "label": meta["label"],
            "input_type": meta["input_type"],
            "required": meta["required"],
            "restart": meta["restart"],
            "help": meta["help"],
            "sensitive": meta["sensitive"],
            "value": "" if meta["sensitive"] else value,
            "configured": bool(value) if meta["sensitive"] else None,
        }
        for key in ("min", "max", "step"):
            if key in meta:
                field[key] = meta[key]
        groups[category].append(field)

    return {
        "categories": [{"name": name, "fields": fields} for name, fields in groups.items()],
        "setup_complete": not needs_setup(),
    }
