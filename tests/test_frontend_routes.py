from __future__ import annotations

from pathlib import Path
from unittest.mock import AsyncMock

import pytest

from app.main import app, templates
from tests.conftest import bootstrap_csrf_client


@pytest.fixture(autouse=True)
def _setup_app(tmp_path, monkeypatch):
    build_dir = tmp_path / "frontend-build"
    build_dir.mkdir()
    (build_dir / "index.html").write_text("<!doctype html><html><body>frontend index</body></html>")
    (build_dir / "asset.js").write_text('console.log("asset")')

    monkeypatch.setattr("app.routes.frontend.FRONTEND_BUILD_DIR", build_dir)
    monkeypatch.setattr("app.routes.frontend.needs_setup", lambda: False)

    mock_paperless = AsyncMock()
    mock_paperless.base_url = "http://test:8000"
    app.state.paperless = mock_paperless
    app.state.ollama = AsyncMock()
    app.state.templates = templates


@pytest.fixture()
def client():
    from starlette.testclient import TestClient

    return bootstrap_csrf_client(TestClient(app, raise_server_exceptions=True))


def test_app_route_serves_frontend_index(client):
    response = client.get("/app")
    assert response.status_code == 200
    assert "frontend index" in response.text


@pytest.mark.parametrize(
    "path",
    [
        "/app/review",
        "/app/tags",
        "/app/correspondents",
        "/app/doctypes",
        "/app/processing",
        "/app/chat",
        "/app/embeddings",
    ],
)
def test_nested_frontend_routes_fall_back_to_index(client, path):
    response = client.get(path)
    assert response.status_code == 200
    assert "frontend index" in response.text


def test_frontend_static_asset_is_served(client):
    response = client.get("/app/asset.js")
    assert response.status_code == 200
    assert "console.log" in response.text


def test_setup_mode_redirects_app_to_setup(monkeypatch, client):
    monkeypatch.setattr("app.routes.frontend.needs_setup", lambda: True)

    response = client.get("/app", follow_redirects=False)
    setup_response = client.get("/app/setup", follow_redirects=False)

    assert response.status_code == 302
    assert response.headers["location"] == "/app/setup"
    assert setup_response.status_code == 200
    assert "frontend index" in setup_response.text


def test_missing_build_returns_helpful_status(monkeypatch, client):
    monkeypatch.setattr(
        "app.routes.frontend.FRONTEND_BUILD_DIR", Path("/tmp/does-not-exist-archibot")
    )
    response = client.get("/app")
    assert response.status_code == 503
    assert "SvelteKit-Build fehlt" in response.text
