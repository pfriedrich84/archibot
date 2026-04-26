"""Statistics routes — counters, timing metrics, and recent activity."""

from __future__ import annotations

from fastapi import APIRouter, Request
from fastapi.responses import RedirectResponse

router = APIRouter(prefix="/stats")


@router.get("")
async def stats_page(_request: Request):
    return RedirectResponse(url="/app/stats", status_code=302)
