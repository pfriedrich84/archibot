"""Persistent, safe, user-facing job event protocol.

These events are intentionally not raw backend logs.  They are small structured
messages suitable for the Svelte UI, CLI rendering, and Telegram summaries.
"""

from __future__ import annotations

import json
from typing import Any

import structlog

from app.db import get_conn

log = structlog.get_logger(__name__)


def record_event(
    job_id: str | None,
    job_type: str,
    event: str,
    message: str,
    *,
    phase: str | None = None,
    level: str = "info",
    document_id: int | None = None,
    data: dict[str, Any] | None = None,
) -> int | None:
    """Persist a safe job event and return its row id."""
    if not job_id:
        return None
    try:
        with get_conn() as conn:
            cur = conn.execute(
                """
                INSERT INTO job_events
                    (job_id, job_type, phase, level, event, document_id, message, data_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    job_id,
                    job_type,
                    phase,
                    level,
                    event,
                    document_id,
                    message,
                    json.dumps(data or {}, ensure_ascii=False),
                ),
            )
            return int(cur.lastrowid)
    except Exception as exc:  # pragma: no cover - event logging must never break jobs
        log.warning("failed to record job event", job_id=job_id, job_event=event, error=str(exc))
        return None


def list_events(job_id: str, *, since: int = 0, limit: int = 250) -> list[dict[str, Any]]:
    try:
        with get_conn() as conn:
            rows = conn.execute(
                """
                SELECT id, job_id, job_type, phase, level, event, document_id, message, data_json, created_at
                FROM job_events
                WHERE job_id = ? AND id > ?
                ORDER BY id ASC
                LIMIT ?
                """,
                (job_id, since, limit),
            ).fetchall()
    except Exception as exc:  # pragma: no cover - event listing must not break CLI/tests
        log.warning("failed to list job events", job_id=job_id, error=str(exc))
        return []
    out: list[dict[str, Any]] = []
    for row in rows:
        item = dict(row)
        try:
            item["data"] = json.loads(item.pop("data_json") or "{}")
        except json.JSONDecodeError:
            item["data"] = {}
        out.append(item)
    return out


def recent_jobs(limit: int = 20) -> list[dict[str, Any]]:
    with get_conn() as conn:
        rows = conn.execute(
            """
            SELECT job_id,
                   MIN(created_at) AS started_at,
                   MAX(created_at) AS last_event_at,
                   MAX(id) AS latest_event_id,
                   MAX(job_type) AS job_type,
                   SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) AS errors,
                   COUNT(*) AS event_count
            FROM job_events
            GROUP BY job_id
            ORDER BY latest_event_id DESC
            LIMIT ?
            """,
            (limit,),
        ).fetchall()
    return [dict(row) for row in rows]


def job_summary(job_id: str) -> dict[str, Any]:
    with get_conn() as conn:
        row = conn.execute(
            """
            SELECT COUNT(*) AS event_count,
                   SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) AS errors,
                   SUM(CASE WHEN event IN ('document_done', 'document_failed', 'document_skipped') THEN 1 ELSE 0 END) AS documents_done,
                   MAX(id) AS latest_event_id,
                   MAX(created_at) AS last_event_at
            FROM job_events
            WHERE job_id = ?
            """,
            (job_id,),
        ).fetchone()
    return dict(row) if row else {}
