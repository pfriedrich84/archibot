"""Reverse proxy routes for the embedded Laravel application."""

from __future__ import annotations

import httpx
from fastapi import APIRouter, Request, Response
from fastapi.responses import JSONResponse

router = APIRouter(tags=["laravel"])

_LARAVEL_UPSTREAM = "http://127.0.0.1:8089"
_HOP_BY_HOP_HEADERS = {
    "connection",
    "keep-alive",
    "proxy-authenticate",
    "proxy-authorization",
    "te",
    "trailer",
    "transfer-encoding",
    "upgrade",
}


def _forward_headers(request: Request) -> dict[str, str]:
    headers = {
        key: value
        for key, value in request.headers.items()
        if key.lower() not in _HOP_BY_HOP_HEADERS and key.lower() != "host"
    }
    headers["host"] = request.headers.get("host", "localhost")
    headers["x-forwarded-proto"] = request.url.scheme
    headers["x-forwarded-host"] = request.headers.get("host", "localhost")
    headers["x-forwarded-prefix"] = "/laravel"
    return headers


async def _proxy(request: Request, path: str) -> Response:
    upstream_url = httpx.URL(f"{_LARAVEL_UPSTREAM}{path}").copy_with(
        query=request.url.query.encode("utf-8")
    )
    try:
        async with httpx.AsyncClient(follow_redirects=False, timeout=30.0) as client:
            upstream = await client.request(
                request.method,
                upstream_url,
                content=await request.body(),
                headers=_forward_headers(request),
            )
    except httpx.HTTPError as exc:
        return JSONResponse(
            status_code=503,
            content={
                "detail": "Laravel application is not available",
                "error": str(exc),
            },
        )

    response = Response(content=upstream.content, status_code=upstream.status_code)
    for key, value in upstream.headers.multi_items():
        if key.lower() not in _HOP_BY_HOP_HEADERS:
            response.headers.append(key, value)
    return response


@router.api_route(
    "/laravel",
    methods=["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS", "HEAD"],
    include_in_schema=False,
)
async def laravel_root(request: Request):
    return await _proxy(request, "/laravel")


@router.api_route(
    "/laravel/{path:path}",
    methods=["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS", "HEAD"],
    include_in_schema=False,
)
async def laravel_app(request: Request, path: str):
    return await _proxy(request, f"/laravel/{path}")


@router.api_route("/build/{path:path}", methods=["GET", "HEAD"], include_in_schema=False)
async def laravel_build_assets(request: Request, path: str):
    return await _proxy(request, f"/build/{path}")
