from __future__ import annotations

import json

import httpx
import pytest

from app.clients.paperless import PaperlessClient


@pytest.mark.asyncio
async def test_create_entities_disable_paperless_auto_matching():
    requests: list[httpx.Request] = []

    async def handler(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        body = json.loads(request.content.decode())
        return httpx.Response(201, json={"id": 99, "name": body["name"]})

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport, base_url="http://paperless/api") as client:
        paperless = PaperlessClient("http://paperless/api", "token")
        paperless._client = client

        await paperless.create_tag("OpenAI")
        await paperless.create_correspondent("OpenAI Ireland Limited")
        await paperless.create_document_type("Invoice")

    assert [request.url.path for request in requests] == [
        "/api/tags/",
        "/api/correspondents/",
        "/api/document_types/",
    ]
    for request in requests:
        body = json.loads(request.content.decode())
        assert body["matching_algorithm"] == 0
        assert body["match"] == ""
