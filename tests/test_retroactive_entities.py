"""Tests for retroactive correspondent and document-type application on approval."""

from __future__ import annotations

import json
import sqlite3

import pytest

from app.config import settings
from app.models import PaperlessDocument
from app.pipeline.committer import retroactive_correspondent_apply, retroactive_doctype_apply


def _insert_entity_suggestion(conn: sqlite3.Connection, **overrides) -> int:
    defaults = {
        "document_id": 42,
        "status": "committed",
        "proposed_title": "Test Doc",
        "proposed_correspondent_name": "New Correspondent",
        "proposed_correspondent_id": None,
        "proposed_doctype_name": "New Type",
        "proposed_doctype_id": None,
    }
    defaults.update(overrides)
    conn.execute(
        """INSERT INTO suggestions
           (document_id, status, proposed_title,
            proposed_correspondent_name, proposed_correspondent_id,
            proposed_doctype_name, proposed_doctype_id)
           VALUES (:document_id, :status, :proposed_title,
                   :proposed_correspondent_name, :proposed_correspondent_id,
                   :proposed_doctype_name, :proposed_doctype_id)""",
        defaults,
    )
    conn.commit()
    return conn.execute("SELECT last_insert_rowid()").fetchone()[0]


@pytest.mark.asyncio
async def test_retroactive_correspondent_patches_committed_doc_still_in_inbox(
    mock_paperless, patch_db, tmp_db
):
    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    _insert_entity_suggestion(conn)
    conn.close()

    mock_paperless.get_document.return_value = PaperlessDocument(
        id=42, title="Test", tags=[settings.paperless_inbox_tag_id], correspondent=None
    )

    patched, pending = await retroactive_correspondent_apply(
        "New Correspondent", 123, mock_paperless
    )

    assert patched == 1
    assert pending == 0
    mock_paperless.patch_document.assert_called_once_with(42, {"correspondent": 123})


@pytest.mark.asyncio
async def test_retroactive_correspondent_skips_committed_doc_without_inbox_tag(
    mock_paperless, patch_db, tmp_db
):
    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    _insert_entity_suggestion(conn)
    conn.close()

    mock_paperless.get_document.return_value = PaperlessDocument(
        id=42, title="Test", tags=[], correspondent=None
    )

    patched, pending = await retroactive_correspondent_apply(
        "New Correspondent", 123, mock_paperless
    )

    assert patched == 0
    assert pending == 0
    mock_paperless.patch_document.assert_not_called()

    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    log = conn.execute(
        "SELECT * FROM audit_log WHERE action = 'retroactive_correspondent'"
    ).fetchone()
    conn.close()
    assert log is not None
    details = json.loads(log["details"])
    assert details["skipped"] is True
    assert details["reason"] == "document_not_in_inbox"


@pytest.mark.asyncio
async def test_retroactive_correspondent_resolves_pending_without_patch(
    mock_paperless, patch_db, tmp_db
):
    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    _insert_entity_suggestion(conn, status="pending")
    conn.close()

    patched, pending = await retroactive_correspondent_apply(
        "New Correspondent", 123, mock_paperless
    )

    assert patched == 0
    assert pending == 1
    mock_paperless.get_document.assert_not_called()
    mock_paperless.patch_document.assert_not_called()


@pytest.mark.asyncio
async def test_retroactive_doctype_patches_committed_doc_still_in_inbox(
    mock_paperless, patch_db, tmp_db
):
    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    _insert_entity_suggestion(conn)
    conn.close()

    mock_paperless.get_document.return_value = PaperlessDocument(
        id=42, title="Test", tags=[settings.paperless_inbox_tag_id], document_type=None
    )

    patched, pending = await retroactive_doctype_apply("New Type", 456, mock_paperless)

    assert patched == 1
    assert pending == 0
    mock_paperless.patch_document.assert_called_once_with(42, {"document_type": 456})


@pytest.mark.asyncio
async def test_retroactive_doctype_skips_committed_doc_without_inbox_tag(
    mock_paperless, patch_db, tmp_db
):
    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    _insert_entity_suggestion(conn)
    conn.close()

    mock_paperless.get_document.return_value = PaperlessDocument(
        id=42, title="Test", tags=[], document_type=None
    )

    patched, pending = await retroactive_doctype_apply("New Type", 456, mock_paperless)

    assert patched == 0
    assert pending == 0
    mock_paperless.patch_document.assert_not_called()

    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    log = conn.execute("SELECT * FROM audit_log WHERE action = 'retroactive_doctype'").fetchone()
    conn.close()
    assert log is not None
    details = json.loads(log["details"])
    assert details["skipped"] is True
    assert details["reason"] == "document_not_in_inbox"


@pytest.mark.asyncio
async def test_retroactive_doctype_resolves_pending_without_patch(mock_paperless, patch_db, tmp_db):
    conn = sqlite3.connect(str(tmp_db))
    conn.row_factory = sqlite3.Row
    _insert_entity_suggestion(conn, status="pending")
    conn.close()

    patched, pending = await retroactive_doctype_apply("New Type", 456, mock_paperless)

    assert patched == 0
    assert pending == 1
    mock_paperless.get_document.assert_not_called()
    mock_paperless.patch_document.assert_not_called()
