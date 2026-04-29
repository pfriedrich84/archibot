from __future__ import annotations

from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest

from app.db import get_conn, init_db
from app.main import app, templates
from tests.conftest import bootstrap_csrf_client


@pytest.fixture(autouse=True)
def _setup_app(tmp_path, monkeypatch):
    monkeypatch.setattr("app.config.settings.data_dir", str(tmp_path))
    monkeypatch.setattr("app.api_data.settings.data_dir", str(tmp_path))
    init_db()

    mock_paperless = AsyncMock()
    mock_paperless.base_url = "http://test:8000"
    mock_paperless.list_correspondents = AsyncMock(return_value=[])
    mock_paperless.list_document_types = AsyncMock(return_value=[])
    mock_paperless.list_storage_paths = AsyncMock(return_value=[])
    mock_paperless.list_tags = AsyncMock(return_value=[])
    mock_paperless.create_tag = AsyncMock(return_value=SimpleNamespace(id=101))
    mock_paperless.create_correspondent = AsyncMock(return_value=SimpleNamespace(id=201))
    mock_paperless.create_document_type = AsyncMock(return_value=SimpleNamespace(id=301))

    app.state.paperless = mock_paperless
    mock_ollama = AsyncMock()
    mock_ollama.list_models = AsyncMock(return_value=["gemma4:e4b", "qwen3-embedding:4b"])
    app.state.ollama = mock_ollama
    app.state.templates = templates
    app.state.scheduler = SimpleNamespace(
        get_job=lambda _job_id: None,
        reschedule_job=lambda *args, **kwargs: None,
        pause_job=lambda *args, **kwargs: None,
        resume_job=lambda *args, **kwargs: None,
    )

    with get_conn() as conn:
        conn.execute(
            "INSERT INTO suggestions (document_id, status, proposed_title, proposed_correspondent_name, proposed_doctype_name, confidence, judge_verdict) VALUES (?,?,?,?,?,?,?)",
            (1, "pending", "Rechnung April", "Stadtwerke", "Rechnung", 87, "agree"),
        )
        conn.execute(
            "INSERT INTO processed_documents (document_id, last_updated_at, last_processed, status) VALUES (?,?,?,?)",
            (1, "2026-01-01T00:00:00", "2026-01-01T00:00:00", "pending"),
        )
        conn.execute(
            "INSERT INTO errors (stage, document_id, message, details) VALUES (?,?,?,?)",
            ("ocr", 1, "OCR failed", "out of memory"),
        )
        conn.execute(
            "INSERT INTO tag_whitelist (name, approved) VALUES (?, ?)",
            ("Neue Idee", 0),
        )
        conn.execute(
            "INSERT INTO tag_blacklist (name, times_seen) VALUES (?, ?)",
            ("Spam", 2),
        )
        conn.execute(
            "INSERT INTO correspondent_whitelist (name, approved) VALUES (?, ?)",
            ("ACME GmbH", 0),
        )
        conn.execute(
            "INSERT INTO doctype_whitelist (name, approved) VALUES (?, ?)",
            ("Vertrag", 0),
        )
        conn.execute(
            "INSERT INTO audit_log (action, document_id, actor, details) VALUES (?,?,?,?)",
            ("commit", 1, "user", "ok"),
        )
        conn.execute(
            "INSERT INTO doc_embedding_meta (document_id, title, correspondent, doctype, indexed_at) VALUES (?,?,?,?,?)",
            (1, "Rechnung April", 10, 20, "2026-01-01T00:00:00"),
        )
        conn.execute(
            "INSERT INTO phase_timing (poll_cycle_id, document_id, phase, started_at, finished_at, duration_ms, success) VALUES (?,?,?,?,?,?,?)",
            ("cycle-1", 1, "classify", "2026-04-20T00:00:00", "2026-04-20T00:00:04", 4000, 1),
        )
        conn.execute(
            "INSERT INTO poll_cycles (id, started_at, finished_at, total_docs, succeeded, failed, skipped) VALUES (?,?,?,?,?,?,?)",
            ("cycle-1", "2026-04-20 00:00:00", "2026-04-20 00:00:04", 1, 1, 0, 0),
        )


@pytest.fixture()
def client():
    from starlette.testclient import TestClient

    return bootstrap_csrf_client(TestClient(app, raise_server_exceptions=True))


def test_dashboard_api_returns_expected_sections(client):
    response = client.get("/api/v1/dashboard")
    assert response.status_code == 200
    payload = response.json()

    assert payload["kpis"]["pending_review"] == 1
    assert payload["kpis"]["errors_24h"] == 1
    assert payload["health"]["setup_complete"] is True
    assert isinstance(payload["recent_errors"], list)
    assert "pipeline" in payload
    assert "reindex" in payload
    assert payload["pipeline"]["last_poll"]["relative_finished"] is not None


def test_system_status_api_reports_legacy_ui_migration_state(client):
    response = client.get("/api/v1/system/status")
    assert response.status_code == 200
    payload = response.json()

    assert payload["app"]["legacy_ui"]["deprecated"] is True
    assert payload["app"]["frontend"]["new_app_path"] == "/app"
    assert payload["logging"]["request_ids"] is True


def test_review_queue_api_returns_pending_suggestions(client):
    response = client.get("/api/v1/review/queue")
    assert response.status_code == 200
    payload = response.json()

    assert payload["total"] == 1
    assert payload["items"][0]["document_id"] == 1
    assert payload["items"][0]["confidence"] == 87


def test_inbox_api_returns_status_counts(client):
    response = client.get("/api/v1/inbox")
    assert response.status_code == 200
    payload = response.json()

    assert payload["total"] == 1
    assert payload["counts"]["pending"] == 1
    assert payload["items"][0]["proposed_title"] == "Rechnung April"


def test_tags_api_returns_whitelist_and_blacklist(client):
    response = client.get("/api/v1/tags")
    assert response.status_code == 200
    payload = response.json()

    assert payload["tags"]["whitelist"][0]["name"] == "Neue Idee"
    assert payload["tags"]["blacklist"][0]["name"] == "Spam"
    assert payload["correspondents"]["whitelist"][0]["name"] == "ACME GmbH"
    assert payload["doctypes"]["whitelist"][0]["name"] == "Vertrag"


def test_stats_api_returns_operational_metrics(client):
    response = client.get("/api/v1/stats")
    assert response.status_code == 200
    payload = response.json()

    assert payload["totals"]["processed_documents"] == 1
    assert payload["totals"]["embedded_documents"] == 1
    assert payload["phase_health"]["classify"]["avg_ms"] == 4000


def test_embeddings_api_returns_recent_rows(client):
    response = client.get("/api/v1/embeddings")
    assert response.status_code == 200
    payload = response.json()

    assert payload["total_embedded"] == 1
    assert payload["items"][0]["title"] == "Rechnung April"


def test_chat_ask_api_returns_answer_and_session(client, monkeypatch):
    async def fake_ask(question, session, paperless, ollama):
        assert question == "Was ist neu?"
        return SimpleNamespace(
            answer="Antwort aus dem Test",
            sources=[{"id": 1, "title": "Rechnung April", "distance": 0.123}],
        )

    monkeypatch.setattr("app.routes.api.ask_chat", fake_ask)

    response = client.post("/api/v1/chat/ask", json={"question": "Was ist neu?"})
    assert response.status_code == 200
    payload = response.json()
    assert payload["session_id"]
    assert payload["answer"] == "Antwort aus dem Test"
    assert payload["sources"][0]["id"] == 1


def test_settings_schema_api_groups_fields(client):
    response = client.get("/api/v1/settings/schema")
    assert response.status_code == 200
    payload = response.json()

    categories = {category["name"] for category in payload["categories"]}
    assert "Paperless" in categories
    assert "GUI" in categories

    paperless_category = next(c for c in payload["categories"] if c["name"] == "Paperless")
    token_field = next(f for f in paperless_category["fields"] if f["name"] == "paperless_token")
    assert token_field["sensitive"] is True
    assert token_field["value"] == ""


def test_ollama_models_api_lists_available_models(client):
    response = client.get("/api/v1/ollama/models")
    assert response.status_code == 200
    assert response.json() == {
        "items": [{"name": "gemma4:e4b"}, {"name": "qwen3-embedding:4b"}]
    }


def test_tag_approval_api_updates_whitelist(client):
    response = client.post("/api/v1/tags/approve", json={"name": "Neue Idee"})
    assert response.status_code == 200
    with get_conn() as conn:
        row = conn.execute(
            "SELECT approved, paperless_id FROM tag_whitelist WHERE name = ?", ("Neue Idee",)
        ).fetchone()
    assert row["approved"] == 1
    assert row["paperless_id"] == 101


def test_job_control_api_starts_poll(client, monkeypatch):
    monkeypatch.setattr("app.routes.api.start_poll_task", lambda: True)
    monkeypatch.setattr(
        "app.routes.api.get_poll_progress",
        lambda: SimpleNamespace(
            running=True,
            phase="prepare",
            done=0,
            total=0,
            succeeded=0,
            failed=0,
            skipped=0,
            cancelled=False,
            error=None,
            started_at="2026-04-27T09:00:00+00:00",
        ),
    )

    response = client.post("/api/v1/jobs/poll/start", json={})
    assert response.status_code == 200
    assert response.json()["running"] is True


def test_save_settings_api_persists_updates(client):
    response = client.post(
        "/api/v1/settings",
        json={"updates": {"poll_interval_seconds": 123, "gui_base_url": "https://example.test"}},
        headers={"accept": "application/json"},
    )
    assert response.status_code == 200
    payload = response.json()

    assert payload["saved"] is True
    assert payload["changed"]["poll_interval_seconds"] == 123
    assert payload["changed"]["gui_base_url"] == "https://example.test"
