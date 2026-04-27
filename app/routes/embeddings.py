"""Embeddings entry route — legacy server-rendered embeddings UI removed."""

from __future__ import annotations

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse, RedirectResponse

router = APIRouter(prefix="/embeddings")


@router.get("")
async def embeddings_page(_request: Request, q: str = "", page: int = 1):
    del q, page
    return RedirectResponse(url="/app/embeddings", status_code=302)


@router.api_route("/{path:path}", methods=["GET", "POST", "PUT", "PATCH", "DELETE"])
async def embeddings_legacy_removed(_request: Request, path: str):
    del path
    return JSONResponse(
        {
            "detail": "Legacy embeddings HTMX endpoints were removed. Use the /app/embeddings frontend and /api/v1/embeddings endpoints.",
            "status": "removed",
        },
        status_code=410,
    )
