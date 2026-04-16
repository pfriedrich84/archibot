"""Tests for retroactive tag application on approval."""

from __future__ import annotations

import json
import sqlite3

import pytest

from app.models import PaperlessDocument
from app.pipeline.committer import retroactive_tag_apply


def _insert_suggestion(conn: sqlite3.Connection, **overrides) -> int:
    defaults = {
        "document_id": 42,
        "status": "committed",
        "proposed_title": "Test Doc",
        "proposed_tags_json": json.dumps(
            [
                {"name": "ExistingTag", "id": 20, "confidence": 90},
                {"name": "NewTag", "id": None, "confidence": 75},
            ]
        ),
    }
    defaults.update(overrides)
    conn.execute(
        """INSERT INTO suggestions
           (document_id, status, proposed_title, proposed_tags_json)
           VALUES (:document_id, :status, :proposed_title, :proposed_tags_json)""",
        defaults,
    )
    conn.commit()
    return conn.execute("SELECT last_insert_rowid()").fetchone()[0]


@pytest.mark.asyncio
async def test_retroactive_patches_committed_doc(mock_paperless, patch_db, tmp_db):
    """Approving a tag should PATCH committed documents to add it."""
    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    _insert_suggestion(conn, document_id=42, status="committed")
    conn.close()

    mock_paperless.get_document.return_value = PaperlessDocument(id=42, title="Test", tags=[20])

    patched, pending = await retroactive_tag_apply("NewTag", 50, mock_paperless)

    assert patched == 1
    assert pending == 0

    # Verify PATCH was called with the new tag added
    mock_paperless.patch_document.assert_called_once()
    call_args = mock_paperless.patch_document.call_args[0]
    assert call_args[0] == 42
    assert 50 in call_args[1]["tags"]
    assert 20 in call_args[1]["tags"]


@pytest.mark.asyncio
async def test_retroactive_resolves_pending_suggestion(mock_paperless, patch_db, tmp_db):
    """Approving a tag should resolve id=null in pending suggestions without PATCHing."""
    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    _insert_suggestion(conn, document_id=42, status="pending")
    conn.close()

    patched, pending = await retroactive_tag_apply("NewTag", 50, mock_paperless)

    assert patched == 0
    assert pending == 1

    # Should NOT have called Paperless for pending suggestions
    mock_paperless.get_document.assert_not_called()
    mock_paperless.patch_document.assert_not_called()

    # Verify the JSON was updated in the DB
    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    row = conn.execute(
        "SELECT proposed_tags_json FROM suggestions WHERE document_id = 42"
    ).fetchone()
    conn.close()
    tags = json.loads(row["proposed_tags_json"])
    new_tag = next(t for t in tags if t["name"] == "NewTag")
    assert new_tag["id"] == 50


@pytest.mark.asyncio
async def test_retroactive_skips_already_tagged_doc(mock_paperless, patch_db, tmp_db):
    """If the document already has the tag, skip the PATCH."""
    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    _insert_suggestion(conn, document_id=42, status="committed")
    conn.close()

    # Document already has tag 50
    mock_paperless.get_document.return_value = PaperlessDocument(id=42, title="Test", tags=[20, 50])

    patched, _pending = await retroactive_tag_apply("NewTag", 50, mock_paperless)

    assert patched == 0
    mock_paperless.patch_document.assert_not_called()


@pytest.mark.asyncio
async def test_retroactive_no_match(mock_paperless, patch_db, tmp_db):
    """If no suggestions contain the tag, counts should be zero."""
    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    _insert_suggestion(
        conn,
        proposed_tags_json=json.dumps([{"name": "Other", "id": None, "confidence": 80}]),
    )
    conn.close()

    patched, pending = await retroactive_tag_apply("NewTag", 50, mock_paperless)

    assert patched == 0
    assert pending == 0


@pytest.mark.asyncio
async def test_retroactive_case_insensitive(mock_paperless, patch_db, tmp_db):
    """Tag name matching should be case-insensitive."""
    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    _insert_suggestion(
        conn,
        document_id=42,
        status="committed",
        proposed_tags_json=json.dumps([{"name": "newtag", "id": None, "confidence": 80}]),
    )
    conn.close()

    mock_paperless.get_document.return_value = PaperlessDocument(id=42, title="Test", tags=[20])

    patched, _pending = await retroactive_tag_apply("NewTag", 50, mock_paperless)

    assert patched == 1


@pytest.mark.asyncio
async def test_retroactive_handles_deleted_document(mock_paperless, patch_db, tmp_db):
    """If the document was deleted in Paperless, skip gracefully."""
    import httpx

    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    _insert_suggestion(conn, document_id=42, status="committed")
    conn.close()

    response = httpx.Response(404, request=httpx.Request("GET", "http://test/api/documents/42/"))
    mock_paperless.get_document.side_effect = httpx.HTTPStatusError(
        "Not Found", request=response.request, response=response
    )

    patched, _pending = await retroactive_tag_apply("NewTag", 50, mock_paperless)

    assert patched == 0


@pytest.mark.asyncio
async def test_retroactive_creates_audit_log(mock_paperless, patch_db, tmp_db):
    """Retroactive tag application should create an audit log entry."""
    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    _insert_suggestion(conn, document_id=42, status="committed")
    conn.close()

    mock_paperless.get_document.return_value = PaperlessDocument(id=42, title="Test", tags=[20])

    await retroactive_tag_apply("NewTag", 50, mock_paperless)

    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    log = conn.execute("SELECT * FROM audit_log WHERE action = 'retroactive_tag'").fetchone()
    conn.close()

    assert log is not None
    assert log["document_id"] == 42
    assert "NewTag" in log["details"]


@pytest.mark.asyncio
async def test_retroactive_multiple_suggestions(mock_paperless, patch_db, tmp_db):
    """Should handle multiple suggestions across committed and pending."""
    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    _insert_suggestion(conn, document_id=10, status="committed")
    _insert_suggestion(conn, document_id=20, status="committed")
    _insert_suggestion(conn, document_id=30, status="pending")
    conn.close()

    mock_paperless.get_document.side_effect = [
        PaperlessDocument(id=10, title="A", tags=[]),
        PaperlessDocument(id=20, title="B", tags=[]),
    ]

    patched, pending = await retroactive_tag_apply("NewTag", 50, mock_paperless)

    assert patched == 2
    assert pending == 1
    assert mock_paperless.patch_document.call_count == 2
