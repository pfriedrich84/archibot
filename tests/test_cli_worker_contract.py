"""The operator CLI must not double as a productive Python actor contract."""

from __future__ import annotations

import inspect
import sys
from pathlib import Path
from unittest.mock import MagicMock

import pytest

from app import cli


def test_operator_cli_has_no_legacy_worker_or_entity_sync_registration() -> None:
    assert "sync-entity-approval" not in cli.COMMANDS
    assert "chat-ask" not in cli.COMMANDS


def test_productive_operator_cli_has_no_classifier_database_access() -> None:
    source = inspect.getsource(cli)
    for forbidden in ("app.db", "init_db", "get_conn", "classifier.db", "db_path"):
        assert forbidden not in source


def test_operator_cli_does_not_accept_legacy_json_contract(monkeypatch) -> None:
    monkeypatch.setattr(cli, "_configure_logging", MagicMock())
    monkeypatch.setattr(sys, "argv", ["archibot", "poll", "--input", "legacy.json"])
    invoke = MagicMock()
    monkeypatch.setattr(cli, "cmd_laravel_maintenance", invoke)

    with pytest.raises(SystemExit, match="1"):
        cli.main()

    invoke.assert_not_called()


def test_review_commit_delegates_to_laravel(monkeypatch) -> None:
    monkeypatch.setattr(cli, "_configure_logging", MagicMock())
    monkeypatch.setattr(sys, "argv", ["archibot", "commit-review", "17", "--user-id=9"])
    invoke = MagicMock()
    monkeypatch.setattr(cli, "cmd_commit_review", invoke)

    cli.main()

    invoke.assert_called_once_with(17, 9)


def test_entity_sync_actor_is_retired() -> None:
    parser = cli.COMMANDS
    assert "sync-entity-approval" not in parser


def test_entity_approval_productive_seam_has_no_sqlite_dependency() -> None:
    root = Path(__file__).resolve().parents[1]
    paths = [
        root / "app" / "actor_runner.py",
        root / "app" / "cli.py",
        root / "laravel" / "app" / "Services" / "EntityApprovalDecisionService.php",
        root / "laravel" / "app" / "Http" / "Controllers" / "EntityApprovalController.php",
    ]
    source = "\n".join(path.read_text(encoding="utf-8") for path in paths)
    for forbidden in ("classifier.db", "app.db", "get_conn", "init_db"):
        assert forbidden not in source
    assert "sync-entity-approval" not in source


def test_invalid_review_id_fails_before_laravel(monkeypatch) -> None:
    monkeypatch.setattr(cli, "_configure_logging", MagicMock())
    monkeypatch.setattr(sys, "argv", ["archibot", "commit-review", "0", "--user-id=9"])
    with pytest.raises(SystemExit, match="1"):
        cli.main()
