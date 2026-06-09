"""Tests for the CLI process-doc command."""

from __future__ import annotations

import asyncio
import sys
from unittest.mock import AsyncMock, MagicMock, patch

import pytest


@pytest.fixture(autouse=True)
def _mock_cli_side_effects(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr("app.cli.init_db", MagicMock())
    monkeypatch.setattr("app.cli._configure_logging", MagicMock())


def _mock_conn_cm(conn: MagicMock) -> MagicMock:
    cm = MagicMock()
    cm.__enter__.return_value = conn
    cm.__exit__.return_value = None
    return cm


def test_cmd_process_doc_force_deletes_processed_row() -> None:
    """cmd_process_doc(..., force=True) should clear processed_documents entry first."""
    mock_paperless = MagicMock()
    mock_paperless.aclose = AsyncMock()
    mock_paperless.get_document = AsyncMock(
        return_value=type("Doc", (), {"id": 224, "title": "Demo"})()
    )
    mock_paperless.list_correspondents = AsyncMock(return_value=[])
    mock_paperless.list_document_types = AsyncMock(return_value=[])
    mock_paperless.list_storage_paths = AsyncMock(return_value=[])
    mock_paperless.list_tags = AsyncMock(return_value=[])

    mock_ollama = MagicMock()
    mock_ollama.aclose = AsyncMock()

    mock_process = AsyncMock(return_value="classified")
    conn = MagicMock()

    with (
        patch("app.cli.PaperlessClient", return_value=mock_paperless),
        patch("app.cli.create_ai_provider", return_value=mock_ollama),
        patch("app.pipeline.document_processing.process_document", mock_process),
        patch("app.db.get_conn", return_value=_mock_conn_cm(conn)),
    ):
        from app.cli import cmd_process_doc

        asyncio.run(cmd_process_doc(224, force=True))

    conn.execute.assert_called_once_with(
        "DELETE FROM processed_documents WHERE document_id = ?", (224,)
    )
    mock_process.assert_called_once()


def test_cmd_process_doc_without_force_keeps_processed_row() -> None:
    """cmd_process_doc(..., force=False) should not delete from processed_documents."""
    mock_paperless = MagicMock()
    mock_paperless.aclose = AsyncMock()
    mock_paperless.get_document = AsyncMock(
        return_value=type("Doc", (), {"id": 224, "title": "Demo"})()
    )
    mock_paperless.list_correspondents = AsyncMock(return_value=[])
    mock_paperless.list_document_types = AsyncMock(return_value=[])
    mock_paperless.list_storage_paths = AsyncMock(return_value=[])
    mock_paperless.list_tags = AsyncMock(return_value=[])

    mock_ollama = MagicMock()
    mock_ollama.aclose = AsyncMock()

    mock_process = AsyncMock(return_value="classified")
    conn = MagicMock()

    with (
        patch("app.cli.PaperlessClient", return_value=mock_paperless),
        patch("app.cli.create_ai_provider", return_value=mock_ollama),
        patch("app.pipeline.document_processing.process_document", mock_process),
        patch("app.db.get_conn", return_value=_mock_conn_cm(conn)),
    ):
        from app.cli import cmd_process_doc

        asyncio.run(cmd_process_doc(224, force=False))

    conn.execute.assert_not_called()
    mock_process.assert_called_once()


def test_main_process_doc_parses_id_and_force(monkeypatch: pytest.MonkeyPatch) -> None:
    """main() routes process-doc through durable Laravel Maintenance."""
    monkeypatch.setattr(sys, "argv", ["cli", "process-doc", "224", "--force"])
    mock_laravel = MagicMock()
    monkeypatch.setattr("app.cli.cmd_laravel_maintenance", mock_laravel)

    from app.cli import main

    main()

    mock_laravel.assert_called_once_with("process_document", force=True, document_id=224)


def test_main_process_doc_requires_id(monkeypatch: pytest.MonkeyPatch) -> None:
    """main() exits with error when process-doc is missing document id."""
    monkeypatch.setattr(sys, "argv", ["cli", "process-doc"])

    from app.cli import main

    with pytest.raises(SystemExit, match="1"):
        main()
