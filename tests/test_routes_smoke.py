"""Smoke tests for admin and legacy-cutover routes."""

from __future__ import annotations

from datetime import UTC, datetime
from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest

from app.db import get_conn, init_db
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

    def test_dashboard_and_system_status_handle_mixed_timestamp_formats(self, client):
        with get_conn() as conn:
            conn.execute(
                "INSERT INTO poll_cycles (id, started_at, finished_at, total_docs, succeeded, failed, skipped) VALUES (?,?,?,?,?,?,?)",
                ("cycle-mixed", "2026-04-20 00:00:00", "2026-04-20 00:00:04", 2, 2, 0, 0),
            )

        app.state.scheduler = SimpleNamespace(
            get_job=lambda _job_id: SimpleNamespace(next_run_time=datetime(2026, 4, 27, 12, 0, tzinfo=UTC))
        )

        dashboard = client.get("/api/v1/dashboard")
        status = client.get("/api/v1/system/status")

        assert dashboard.status_code == 200
        assert status.status_code == 200
        assert dashboard.json()["pipeline"]["last_poll"]["relative_finished"] is not None
        assert status.json()["jobs"]["poll"]["next_run_at"].endswith("+00:00")
