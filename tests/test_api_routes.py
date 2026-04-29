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


def test_paperless_test_api_fetches_tags_for_setup(client, monkeypatch):
    created = {}

    class FakePaperlessClient:
        def __init__(self, base_url, token):
            created["base_url"] = base_url
            created["token"] = token

        async def list_tags(self):
            return [SimpleNamespace(id=99, name="Posteingang")]

        async def aclose(self):
            created["closed"] = True

    monkeypatch.setattr("app.clients.paperless.PaperlessClient", FakePaperlessClient)

    response = client.post(
        "/api/v1/paperless/test",
        json={"paperless_url": "https://paperless.example", "paperless_token": "secret"},
    )

    assert response.status_code == 200
    assert response.json() == {"ok": True, "items": [{"id": 99, "name": "Posteingang"}]}
    assert created == {"base_url": "https://paperless.example", "token": "secret", "closed": True}


def test_ollama_test_api_fetches_models_for_setup(client, monkeypatch):
    created = {}

    class FakeOllamaClient:
        def __init__(self, base_url):
            created["base_url"] = base_url

        async def list_models(self):
            return ["gemma4:e4b", "qwen3-embedding:4b"]

        async def aclose(self):
            created["closed"] = True

    monkeypatch.setattr("app.clients.ollama.OllamaClient", FakeOllamaClient)

    response = client.post("/api/v1/ollama/test", json={"ollama_url": "http://ollama:11434"})

    assert response.status_code == 200
    assert response.json() == {
        "ok": True,
        "items": [{"name": "gemma4:e4b"}, {"name": "qwen3-embedding:4b"}],
    }
    assert created == {"base_url": "http://ollama:11434", "closed": True}


def test_chat_ask_api_returns_answer_and_session(client, monkeypatch):
    from app.chat import _sessions

    _sessions.clear()

    async def fake_ask(question, session, paperless, ollama):
        assert question == "Was ist neu?"
        session.messages.append({"role": "user", "content": question})
        session.messages.append({"role": "assistant", "content": "Antwort aus dem Test"})
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

    snapshot = client.get("/api/v1/chat").json()
    assert snapshot["sessions"][0]["title"] == "Was ist neu?"
    assert snapshot["sessions"][0]["origin"] == "web"

    session_response = client.get(f"/api/v1/chat/sessions/{payload['session_id']}")
    assert session_response.status_code == 200
    assert session_response.json()["messages"][1]["content"] == "Antwort aus dem Test"

    delete_response = client.delete(f"/api/v1/chat/sessions/{payload['session_id']}")
    assert delete_response.status_code == 200
    assert client.get("/api/v1/chat").json()["sessions"] == []


def test_settings_save_initializes_paperless_before_tag_validation(client, monkeypatch):
    from app.config import settings

    original_url = settings.paperless_url
    original_token = settings.paperless_token
    original_inbox = settings.paperless_inbox_tag_id
    app.state.paperless = None

    class FakePaperlessClient:
        def __init__(self, *args, **kwargs):
            self.base_url = settings.paperless_url

        async def list_tags(self):
            return [SimpleNamespace(id=123, name="Posteingang")]

        async def aclose(self):
            pass

    monkeypatch.setattr("app.clients.paperless.PaperlessClient", FakePaperlessClient)
    object.__setattr__(settings, "paperless_url", "https://paperless.example")
    object.__setattr__(settings, "paperless_token", "token")
    object.__setattr__(settings, "paperless_inbox_tag_id", 0)

    try:
        response = client.post(
            "/api/v1/settings",
            json={"updates": {"paperless_inbox_tag_id": 123}},
        )
    finally:
        object.__setattr__(settings, "paperless_url", original_url)
        object.__setattr__(settings, "paperless_token", original_token)
        object.__setattr__(settings, "paperless_inbox_tag_id", original_inbox)

    assert response.status_code == 200
    assert response.json()["field_errors"] == {}


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

    all_fields = [field for category in payload["categories"] for field in category["fields"]]
    assert not any(field["name"] == "enable_ocr_correction" for field in all_fields)
    ollama_model = next(field for field in all_fields if field["name"] == "ollama_model")
    assert ollama_model["input_type"] == "model_select"

    context_max_distance = next(
        field for field in all_fields if field["name"] == "context_max_distance"
    )
    assert context_max_distance["input_type"] == "slider"
    assert context_max_distance["value"] == 0.5
    assert context_max_distance["min"] == 0.0
    assert context_max_distance["max"] == 1.0
    assert context_max_distance["step"] == 0.1
    assert "0 = unlimited" in context_max_distance["help"]
    assert "0 = unbegrenzt" in context_max_distance["help"].lower()

    phase_ocr_category = next(c for c in payload["categories"] if c["name"] == "Phase 1: OCR")
    assert phase_ocr_category["fields"][0]["name"] == "ocr_requested_tag_id"
    ocr_mode = next(f for f in phase_ocr_category["fields"] if f["name"] == "ocr_mode")
    assert ocr_mode["input_type"] == "ocr_mode_select"


def test_ollama_models_api_lists_available_models(client):
    response = client.get("/api/v1/ollama/models")
    assert response.status_code == 200
    assert response.json() == {"items": [{"name": "gemma4:e4b"}, {"name": "qwen3-embedding:4b"}]}


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
