"""OCR correction routes — placeholder for future implementation."""

from __future__ import annotations

from fastapi import APIRouter, Request
from fastapi.responses import RedirectResponse

router = APIRouter(prefix="/ocr")


@router.get("")
async def ocr_list(request: Request):
    return RedirectResponse(url="/app/settings", status_code=302)
