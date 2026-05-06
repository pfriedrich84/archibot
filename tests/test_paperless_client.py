from __future__ import annotations

import json

import httpx
import pytest

from app.clients.paperless import PaperlessClient


@pytest.mark.asyncio
async def test_search_documents_url_encodes_filter_values():
    requests: list[httpx.Request] = []

    async def handler(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        return httpx.Response(200, json={"results": []})

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport, base_url="http://paperless/api") as client:
        paperless = PaperlessClient("http://paperless", "token")
        paperless._client = client

        await paperless.search_documents(
            query="A&B = Vertrag?",
            tags=["H&M", "A&O"],
            correspondent="Müller & Söhne",
            document_type="Rechnung #1",
            page_size=10,
        )

    assert len(requests) == 1
    request = requests[0]
    assert request.url.path == "/api/documents/"
    assert request.url.params["page_size"] == "10"
    assert request.url.params["query"] == "A&B = Vertrag?"
    assert request.url.params["correspondent__name__icontains"] == "Müller & Söhne"
    assert request.url.params["document_type__name__icontains"] == "Rechnung #1"
    assert request.url.params.get_list("tags__name__icontains") == ["H&M", "A&O"]


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
