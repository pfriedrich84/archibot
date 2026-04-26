"""Smoke tests for admin and legacy-cutover routes."""

from __future__ import annotations

from unittest.mock import AsyncMock

import pytest

from app.db import init_db
from app.main import app, templates
from tests.conftest import bootstrap_csrf_client


@pytest.fixture(autouse=True)
def _setup_app(tmp_path, monkeypatch):
    """Initialize the app with a temp DB and mocked clients."""
    monkeypatch.setattr("app.config.settings.data_dir", str(tmp_path))

    init_db()

    mock_paperless = AsyncMock()
    mock_paperless.base_url = "http://test:8000"
    mock_paperless.list_correspondents = AsyncMock(return_value=[])
    mock_paperless.list_document_types = AsyncMock(return_value=[])
    mock_paperless.list_storage_paths = AsyncMock(return_value=[])
    mock_paperless.list_tags = AsyncMock(return_value=[])

    app.state.paperless = mock_paperless
    app.state.ollama = AsyncMock()
    app.state.templates = templates


@pytest.fixture()
def client():
    from starlette.testclient import TestClient

    return bootstrap_csrf_client(TestClient(app, raise_server_exceptions=True))


class TestRouteSmoke:
    """Legacy GET routes should redirect to the new admin frontend or return safe responses."""

    def test_dashboard_redirects_to_admin_app(self, client):
        r = client.get("/", follow_redirects=False)
        assert r.status_code == 302
        assert r.headers["location"] == "/app/"

    def test_review_list_redirects(self, client):
        r = client.get("/review", follow_redirects=False)
        assert r.status_code == 302
        assert r.headers["location"] == "/app/review"

    def test_review_detail_not_found(self, client):
        r = client.get("/review/99999")
        assert r.status_code == 404

    def test_approvals_entry_redirects(self, client):
        r = client.get("/approvals", follow_redirects=False)
        assert r.status_code == 302
        assert r.headers["location"] == "/app/tags"

    def test_tags_redirect(self, client):
        r = client.get("/tags", follow_redirects=False)
        assert r.status_code == 302
        assert r.headers["location"] == "/app/tags"

    def test_errors_redirect(self, client):
        r = client.get("/errors", follow_redirects=False)
        assert r.status_code == 302
        assert r.headers["location"] == "/app/errors"

    def test_stats_redirect(self, client):
        r = client.get("/stats", follow_redirects=False)
        assert r.status_code == 302
        assert r.headers["location"] == "/app/stats"

    def test_settings_redirect(self, client):
        r = client.get("/settings", follow_redirects=False)
        assert r.status_code == 302
        assert r.headers["location"] == "/app/settings"

    def test_chat_redirect(self, client):
        r = client.get("/chat", follow_redirects=False)
        assert r.status_code == 302
        assert r.headers["location"] == "/app/chat"

    def test_embeddings_redirect(self, client):
        r = client.get("/embeddings", follow_redirects=False)
        assert r.status_code == 302
        assert r.headers["location"] == "/app/embeddings"

    def test_embeddings_search(self, client):
        r = client.get("/embeddings/search")
        assert r.status_code == 200

    def test_healthz(self, client):
        r = client.get("/healthz")
        assert r.status_code == 200
        assert r.json() == {"status": "ok"}
