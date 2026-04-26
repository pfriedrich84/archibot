"""JSON API routes used by the new SvelteKit admin frontend."""

from __future__ import annotations

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

router = APIRouter(prefix="/api/v1", tags=["api"])


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
