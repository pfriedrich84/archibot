"""Tests for CSRF protection and secure cookie behavior."""

from __future__ import annotations

from unittest.mock import AsyncMock, patch

import pytest

from app.db import EMBED_DIM, init_db
from app.main import app, templates
from app.models import PaperlessDocument
from tests.conftest import bootstrap_csrf_client

_SAMPLE_DOC = PaperlessDocument(
    id=42,
    title="Test Document",
    content="Test content",
    tags=[99],
)


@pytest.fixture(autouse=True)
def _setup_app(tmp_path, monkeypatch):
    monkeypatch.setattr("app.config.settings.data_dir", str(tmp_path))
    monkeypatch.setattr("app.config.settings.webhook_secret", "")
    init_db()

    mock_paperless = AsyncMock()
    mock_paperless.base_url = "http://test:8000"
    mock_paperless.get_document = AsyncMock(return_value=_SAMPLE_DOC)
    mock_paperless.list_correspondents = AsyncMock(return_value=[])
    mock_paperless.list_document_types = AsyncMock(return_value=[])
    mock_paperless.list_storage_paths = AsyncMock(return_value=[])
    mock_paperless.list_tags = AsyncMock(return_value=[])

    mock_ollama = AsyncMock()
    mock_ollama.embed = AsyncMock(return_value=[0.1] * EMBED_DIM)

    app.state.paperless = mock_paperless
    app.state.ollama = mock_ollama
    app.state.templates = templates


@pytest.fixture()
def client():
    from starlette.testclient import TestClient

    return bootstrap_csrf_client(TestClient(app, raise_server_exceptions=False))


def test_post_requires_csrf_token():
    from starlette.testclient import TestClient

    raw_client = TestClient(app, raise_server_exceptions=False)
    raw_client.get("/healthz")
    r = raw_client.post("/chat/clear")
    assert r.status_code == 403
    assert "CSRF" in r.text


def test_post_accepts_valid_csrf_token(client):
    r = client.post("/chat/clear")
    assert r.status_code == 200


@patch("app.routes.webhook._process_document", new_callable=AsyncMock)
def test_webhook_is_exempt_from_csrf(mock_process, client):
    client.headers.pop("X-CSRF-Token", None)
    r = client.post("/webhook/new", json={"document_id": 42})
    assert r.status_code == 200
    mock_process.assert_awaited_once()


def test_chat_page_redirects_to_admin_app(client):
    r = client.get("/chat", follow_redirects=False, headers={"x-forwarded-proto": "https"})
    assert r.status_code == 302
    assert r.headers["location"] == "/app/chat"
