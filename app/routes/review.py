"""Review entry route — legacy server-rendered review UI removed."""

from __future__ import annotations

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse, RedirectResponse

router = APIRouter(prefix="/review")


@router.get("")
async def review_list(_request: Request):
    return RedirectResponse(url="/app/review", status_code=302)


@router.get("/{suggestion_id}")
async def review_detail(_request: Request, suggestion_id: int):
    del suggestion_id
    return RedirectResponse(url="/app/review", status_code=302)


@router.api_route("/{path:path}", methods=["POST", "PUT", "PATCH", "DELETE"])
async def review_legacy_removed(_request: Request, path: str):
    del path
    return JSONResponse(
        {
            "detail": "Legacy HTMX review endpoints were removed. Use the /app/review frontend and /api/v1/review endpoints.",
            "status": "removed",
        },
        status_code=410,
    )
