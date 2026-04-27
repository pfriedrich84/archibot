"""Settings entry route — legacy server-rendered settings UI removed."""

from __future__ import annotations

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse, RedirectResponse

router = APIRouter(prefix="/settings")


@router.get("")
async def settings_page(_request: Request):
    return RedirectResponse(url="/app/settings", status_code=302)


@router.api_route("/{path:path}", methods=["GET", "POST", "PUT", "PATCH", "DELETE"])
async def settings_legacy_removed(_request: Request, path: str):
    del path
    return JSONResponse(
        {
            "detail": "Legacy HTMX settings endpoints were removed. Use the /app/settings frontend and /api/v1/settings endpoints.",
            "status": "removed",
        },
        status_code=410,
    )
