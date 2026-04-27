"""Chat entry route — legacy server-rendered chat removed."""

from __future__ import annotations

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse, RedirectResponse

router = APIRouter(prefix="/chat")


@router.get("")
async def chat_page(_request: Request):
    return RedirectResponse(url="/app/chat", status_code=302)


@router.api_route("/{path:path}", methods=["POST", "PUT", "PATCH", "DELETE"])
async def chat_legacy_removed(_request: Request, path: str):
    del path
    return JSONResponse(
        {
            "detail": "Legacy HTMX chat endpoints were removed. Use the /app/chat frontend and /api/v1/chat endpoints.",
            "status": "removed",
        },
        status_code=410,
    )
