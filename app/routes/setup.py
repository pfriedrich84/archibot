"""Setup entry route — legacy server-rendered wizard removed."""

from __future__ import annotations

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse, RedirectResponse

router = APIRouter(prefix="/setup")


@router.get("")
async def setup_page(_request: Request):
    return RedirectResponse(url="/app/setup", status_code=303)


@router.api_route("/{path:path}", methods=["GET", "POST", "PUT", "PATCH", "DELETE"])
async def setup_legacy_removed(_request: Request, path: str):
    del path
    return JSONResponse(
        {
            "detail": "Legacy setup wizard was removed. Use /app/setup.",
            "status": "removed",
        },
        status_code=410,
    )
