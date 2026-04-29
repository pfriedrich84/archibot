from __future__ import annotations

import re
import sqlite3
from pathlib import Path

import pytest
from starlette.testclient import TestClient

from app.config import needs_setup, settings
from app.db import SCHEMA, init_db, mark_setup_required
from app.main import app


@pytest.fixture()
def data_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    monkeypatch.setattr(settings, "data_dir", str(tmp_path))
    monkeypatch.setattr(settings, "paperless_url", "http://paperless.test")
    monkeypatch.setattr(settings, "paperless_token", "token")
    monkeypatch.setattr(settings, "paperless_inbox_tag_id", 99)
    return tmp_path


def _schema_without_virtual_tables() -> str:
    schema = re.sub(
        r"CREATE VIRTUAL TABLE IF NOT EXISTS doc_embeddings.*?;",
        "",
        SCHEMA,
        flags=re.DOTALL,
    )
    schema = re.sub(
        r"CREATE VIRTUAL TABLE IF NOT EXISTS doc_fts.*?;",
        "",
        schema,
        flags=re.DOTALL,
    )
    return schema


def _init_sqlite_db(path: Path) -> None:
    conn = sqlite3.connect(path)
    conn.executescript(_schema_without_virtual_tables())
    conn.close()


def test_needs_setup_when_db_file_is_missing(data_dir: Path) -> None:
    assert needs_setup() is True


def test_needs_setup_when_db_file_is_empty(data_dir: Path) -> None:
    settings.db_path.write_bytes(b"")

    assert needs_setup() is True


def test_needs_setup_when_reset_marker_is_present(data_dir: Path) -> None:
    _init_sqlite_db(settings.db_path)
    mark_setup_required()

    assert needs_setup() is True


def test_needs_setup_false_when_schema_exists_and_required_config_is_present(
    data_dir: Path,
) -> None:
    _init_sqlite_db(settings.db_path)

    assert needs_setup() is False


def test_needs_setup_false_when_setup_marker_exists(data_dir: Path) -> None:
    _init_sqlite_db(settings.db_path)
    conn = sqlite3.connect(settings.db_path)
    conn.execute(
        "INSERT INTO app_state(key, value) VALUES(?, ?)",
        ("setup_completed_at", "2026-04-29 10:00:00"),
    )
    conn.commit()
    conn.close()

    assert needs_setup() is False


def test_needs_setup_false_for_legacy_populated_db_without_marker(data_dir: Path) -> None:
    _init_sqlite_db(settings.db_path)
    conn = sqlite3.connect(settings.db_path)
    conn.execute(
        "INSERT INTO processed_documents(document_id, last_updated_at, last_processed, status) VALUES(?, ?, ?, ?)",
        (1, "2026-04-29T10:00:00", "2026-04-29T10:00:00", "committed"),
    )
    conn.commit()
    conn.close()

    assert needs_setup() is False


def test_setup_mode_allows_frontend_setup_route_and_settings_api(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch, data_dir: Path
) -> None:
    build_dir = tmp_path / "frontend-build"
    build_dir.mkdir()
    (build_dir / "index.html").write_text("frontend setup page")

    monkeypatch.setattr("app.routes.frontend.FRONTEND_BUILD_DIR", build_dir)
    monkeypatch.setattr("app.main.needs_setup", lambda: True)
    monkeypatch.setattr("app.api_data.needs_setup", lambda: True)

    init_db()
    app.state.paperless = None
    app.state.ollama = None
    client = TestClient(app, raise_server_exceptions=True)

    setup_page = client.get("/app/setup", follow_redirects=False)
    assert setup_page.status_code == 200
    assert "frontend setup page" in setup_page.text

    settings_schema = client.get("/api/v1/settings/schema", follow_redirects=False)
    assert settings_schema.status_code == 200

    system_status = client.get("/api/v1/system/status", follow_redirects=False)
    assert system_status.status_code == 200
    assert system_status.headers["content-type"].startswith("application/json")
    assert system_status.json()["app"]["setup_complete"] is False

    paperless_tags = client.get("/api/v1/paperless/tags", follow_redirects=False)
    assert paperless_tags.status_code == 200
    assert paperless_tags.json() == {"items": []}

    ollama_models = client.get("/api/v1/ollama/models", follow_redirects=False)
    assert ollama_models.status_code == 200
    assert ollama_models.json() == {"items": []}

    redirect = client.get("/review", follow_redirects=False)
    assert redirect.status_code == 302
    assert redirect.headers["location"] == "/setup"
