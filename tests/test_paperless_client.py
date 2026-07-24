from __future__ import annotations

import json

import httpx
import pytest

from app.clients.paperless import PaperlessClient


def test_paperless_client_rejects_empty_token():
    with pytest.raises(ValueError, match="Paperless API token is empty"):
        PaperlessClient("http://paperless", "")


@pytest.mark.asyncio
@pytest.mark.parametrize(
    "field",
    ["ocr", "content", "file", "files", "version", "versions", "storage_path", "owner"],
)
async def test_paperless_client_rejects_fields_outside_manual_metadata_seam(field: str):
    paperless = PaperlessClient("http://paperless", "token")

    with pytest.raises(ValueError, match="prohibited fields"):
        await paperless.patch_document(42, {field: "forbidden"})

    await paperless.aclose()


@pytest.mark.asyncio
async def test_reviewed_storage_path_can_only_fill_an_absent_live_value():
    requests_seen: list[httpx.Request] = []

    async def handler(request: httpx.Request) -> httpx.Response:
        requests_seen.append(request)
        if request.method == "GET":
            return httpx.Response(
                200,
                json={
                    "id": 42,
                    "title": "Doc",
                    "storage_path": None,
                    "checksum": "abc",
                    "versions": [{"id": 42, "checksum": "abc"}],
                },
            )
        return httpx.Response(200, json={})

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport, base_url="http://paperless/api") as client:
        paperless = PaperlessClient("http://paperless", "token")
        paperless._client = client
        await paperless.patch_reviewed_document(42, {"storage_path": 7, "title": "Reviewed"})

    assert [request.method for request in requests_seen] == ["GET", "PATCH"]
    assert json.loads(requests_seen[-1].content) == {"storage_path": 7, "title": "Reviewed"}


@pytest.mark.asyncio
@pytest.mark.parametrize("proposed", [None, 0, -1, True, "7"])
async def test_reviewed_storage_path_rejects_non_assignment_values_before_dispatch(proposed):
    requests_seen: list[httpx.Request] = []

    async def handler(request: httpx.Request) -> httpx.Response:
        requests_seen.append(request)
        return httpx.Response(200, json={})

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport, base_url="http://paperless/api") as client:
        paperless = PaperlessClient("http://paperless", "token")
        paperless._client = client
        with pytest.raises(ValueError, match="positive ID"):
            await paperless.patch_reviewed_document(42, {"storage_path": proposed})

    assert requests_seen == []


@pytest.mark.asyncio
async def test_reviewed_storage_path_fails_closed_when_live_field_is_missing():
    requests_seen: list[httpx.Request] = []

    async def handler(request: httpx.Request) -> httpx.Response:
        requests_seen.append(request)
        return httpx.Response(200, json={"id": 42, "title": "Doc"})

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport, base_url="http://paperless/api") as client:
        paperless = PaperlessClient("http://paperless", "token")
        paperless._client = client
        with pytest.raises(ValueError, match="must report a null storage path"):
            await paperless.patch_reviewed_document(42, {"storage_path": 7})

    assert [request.method for request in requests_seen] == ["GET"]


@pytest.mark.asyncio
async def test_reviewed_storage_path_rejects_overwrite_before_patch_dispatch():
    requests_seen: list[httpx.Request] = []

    async def handler(request: httpx.Request) -> httpx.Response:
        requests_seen.append(request)
        return httpx.Response(
            200,
            json={
                "id": 42,
                "title": "Doc",
                "storage_path": 9,
                "checksum": "abc",
                "versions": [{"id": 42, "checksum": "abc"}],
            },
        )

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport, base_url="http://paperless/api") as client:
        paperless = PaperlessClient("http://paperless", "token")
        paperless._client = client
        with pytest.raises(ValueError, match="immutable"):
            await paperless.patch_reviewed_document(42, {"storage_path": 7})

    assert [request.method for request in requests_seen] == ["GET"]


@pytest.mark.asyncio
@pytest.mark.asyncio
async def test_paperless_client_uses_api10_accept_header_and_rejects_unsupported_versions():
    requests: list[httpx.Request] = []

    async def handler(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        return httpx.Response(200, json={"settings": {"version": "2.9.0"}})

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(
        transport=transport,
        base_url="http://paperless/api",
        headers={"Authorization": "Token token", "Accept": "application/json; version=10"},
    ) as client:
        paperless = PaperlessClient("http://paperless", "token")
        paperless._client = client
        assert await paperless.ping() is False

    assert requests[0].headers.get_list("accept")[-1] == "application/json; version=10"


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
@pytest.mark.asyncio
async def test_reviewed_storage_path_fails_closed_when_document_version_is_not_verifiable():
    requests_seen: list[httpx.Request] = []

    async def handler(request: httpx.Request) -> httpx.Response:
        requests_seen.append(request)
        return httpx.Response(200, json={"id": 42, "title": "Doc", "storage_path": None})

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport, base_url="http://paperless/api") as client:
        paperless = PaperlessClient("http://paperless", "token")
        paperless._client = client
        with pytest.raises(ValueError, match="not verifiable"):
            await paperless.patch_reviewed_document(42, {"storage_path": 7})

    assert [request.method for request in requests_seen] == ["GET"]


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
