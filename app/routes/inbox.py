"""Inbox entry route — legacy server-rendered inbox removed."""

from __future__ import annotations

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse, RedirectResponse

router = APIRouter(prefix="/inbox")


@router.get("")
async def inbox_list(_request: Request):
    return RedirectResponse(url="/app/inbox", status_code=302)


@router.api_route("/{path:path}", methods=["GET", "POST", "PUT", "PATCH", "DELETE"])
async def inbox_legacy_removed(_request: Request, path: str):
    del path
    return JSONResponse(
        {
            "detail": "Legacy HTMX inbox endpoints were removed. Use the /app/inbox frontend and /api/v1/inbox endpoints.",
            "status": "removed",
        },
        status_code=410,
    )
