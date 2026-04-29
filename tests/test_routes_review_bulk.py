"""Tests for native API bulk approve/reject in the review queue."""

from __future__ import annotations

import json
from unittest.mock import AsyncMock

import pytest

from app.db import init_db
from app.main import app, templates
from app.models import PaperlessDocument
from tests.conftest import bootstrap_csrf_client


def _insert_suggestion(conn, sid, doc_id, *, status="pending", confidence=75):
    conn.execute(
        """INSERT INTO suggestions
           (id, document_id, status, confidence,
            proposed_title, proposed_date,
            proposed_correspondent_id, proposed_doctype_id,
            proposed_storage_path_id, proposed_tags_json)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            sid,
            doc_id,
            status,
            confidence,
            f"Title for doc {doc_id}",
            "2025-01-15",
            2,
            10,
            30,
            json.dumps([{"name": "Finanzen", "id": 20}]),
        ),
    )
    conn.commit()


@pytest.fixture(autouse=True)
def _setup_app(tmp_path, monkeypatch):
    monkeypatch.setattr("app.config.settings.data_dir", str(tmp_path))
    init_db()

    mock_paperless = AsyncMock()
    mock_paperless.base_url = "http://test:8000"
    mock_paperless.list_correspondents = AsyncMock(return_value=[])
    mock_paperless.list_document_types = AsyncMock(return_value=[])
    mock_paperless.list_storage_paths = AsyncMock(return_value=[])
    mock_paperless.list_tags = AsyncMock(return_value=[])
    mock_paperless.get_document = AsyncMock(
        return_value=PaperlessDocument(id=1, title="test", tags=[99])
    )
    mock_paperless.patch_document = AsyncMock(return_value=None)

    app.state.paperless = mock_paperless
    app.state.ollama = AsyncMock()
    app.state.templates = templates


@pytest.fixture()
def client():
    from starlette.testclient import TestClient

    return bootstrap_csrf_client(TestClient(app, raise_server_exceptions=True))


class TestBulkApproveApi:
    def test_commits_selected(self, client, patch_db, db_conn):
        _insert_suggestion(db_conn, 1, 100)
        _insert_suggestion(db_conn, 2, 200)

        r = client.post("/api/v1/review/bulk/accept", json={"suggestion_ids": [1, 2]})
        assert r.status_code == 200
        payload = r.json()
        assert payload["succeeded"] == 2
        assert payload["failed"] == 0

        rows = db_conn.execute("SELECT id, status FROM suggestions ORDER BY id").fetchall()
        assert [(row["id"], row["status"]) for row in rows] == [(1, "committed"), (2, "committed")]

    def test_empty_selection(self, client, patch_db):
        r = client.post("/api/v1/review/bulk/accept", json={"suggestion_ids": []})
        assert r.status_code == 400

    def test_skips_non_pending(self, client, patch_db, db_conn):
        _insert_suggestion(db_conn, 1, 100, status="pending")
        _insert_suggestion(db_conn, 2, 200, status="committed")

        r = client.post("/api/v1/review/bulk/accept", json={"suggestion_ids": [1, 2]})
        assert r.status_code == 200
        payload = r.json()
        assert payload["succeeded"] == 1
        assert payload["skipped"] == 1
        assert payload["statuses"] == {"1": "committed", "2": "skipped"}

    def test_partial_failure(self, client, patch_db, db_conn):
        _insert_suggestion(db_conn, 1, 100)
        _insert_suggestion(db_conn, 2, 200)

        async def get_doc_side_effect(doc_id):
            if doc_id == 200:
                raise RuntimeError("Paperless unreachable")
            return PaperlessDocument(id=doc_id, title="test", tags=[99])

        app.state.paperless.get_document = AsyncMock(side_effect=get_doc_side_effect)

        r = client.post("/api/v1/review/bulk/accept", json={"suggestion_ids": [1, 2]})
        assert r.status_code == 200
        payload = r.json()
        assert payload["succeeded"] == 1
        assert payload["failed"] == 1
        assert payload["ok"] is False

    def test_uses_proposed_values(self, client, patch_db, db_conn):
        _insert_suggestion(db_conn, 1, 100)

        r = client.post("/api/v1/review/bulk/accept", json={"suggestion_ids": [1]})
        assert r.status_code == 200

        call_args = app.state.paperless.patch_document.call_args
        assert call_args[0][0] == 100
        fields_arg = call_args[0][1]
        assert fields_arg["title"] == "Title for doc 100"
        assert fields_arg["created_date"] == "2025-01-15"
        assert fields_arg["correspondent"] == 2
        assert fields_arg["document_type"] == 10
        assert fields_arg["storage_path"] == 30


class TestBulkRejectApi:
    def test_rejects_selected(self, client, patch_db, db_conn):
        _insert_suggestion(db_conn, 1, 100)
        _insert_suggestion(db_conn, 2, 200)

        r = client.post("/api/v1/review/bulk/reject", json={"suggestion_ids": [1, 2]})
        assert r.status_code == 200
        payload = r.json()
        assert payload["succeeded"] == 2

        rows = db_conn.execute("SELECT id, status FROM suggestions ORDER BY id").fetchall()
        assert [(row["id"], row["status"]) for row in rows] == [(1, "rejected"), (2, "rejected")]

        audit = db_conn.execute("SELECT action FROM audit_log ORDER BY id").fetchall()
        assert len(audit) == 2
        assert all(row["action"] == "reject" for row in audit)

    def test_empty_selection(self, client, patch_db):
        r = client.post("/api/v1/review/bulk/reject", json={"suggestion_ids": []})
        assert r.status_code == 400

    def test_skips_non_pending(self, client, patch_db, db_conn):
        _insert_suggestion(db_conn, 1, 100, status="pending")
        _insert_suggestion(db_conn, 2, 200, status="rejected")

        r = client.post("/api/v1/review/bulk/reject", json={"suggestion_ids": [1, 2]})
        assert r.status_code == 200
        payload = r.json()
        assert payload["succeeded"] == 1
        assert payload["skipped"] == 1
        assert payload["statuses"] == {"1": "rejected", "2": "skipped"}


class TestReviewRedirects:
    def test_review_get_redirects_to_admin_app(self, client, patch_db, db_conn):
        _insert_suggestion(db_conn, 1, 100)
        r = client.get("/review", follow_redirects=False)
        assert r.status_code == 302
        assert r.headers["location"] == "/app/review"

    def test_review_get_redirects_even_with_multiple_suggestions(self, client, patch_db, db_conn):
        _insert_suggestion(db_conn, 1, 100, confidence=90)
        _insert_suggestion(db_conn, 2, 200, confidence=50)
        r = client.get("/review", follow_redirects=False)
        assert r.status_code == 302
        assert r.headers["location"] == "/app/review"
