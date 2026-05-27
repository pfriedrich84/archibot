"""Tests for the CLI reset command."""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path
from unittest.mock import MagicMock

import pytest

from app.cli import cmd_reset


@pytest.fixture()
def data_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    """Set DATA_DIR to a temp directory and mock the Laravel reset runner."""
    monkeypatch.setattr("app.config.settings.data_dir", str(tmp_path))
    artisan = tmp_path / "laravel" / "artisan"
    artisan.parent.mkdir()
    artisan.write_text("<?php // fake artisan")
    monkeypatch.setattr("app.cli._laravel_artisan_path", lambda: artisan)
    monkeypatch.setattr(
        "app.cli.subprocess.run",
        MagicMock(return_value=subprocess.CompletedProcess(args=[], returncode=0)),
    )
    return tmp_path


def _create_db_files(data_dir: Path) -> tuple[Path, Path, Path]:
    """Create fake legacy DB + WAL/SHM files and return their paths."""
    db = data_dir / "classifier.db"
    wal = data_dir / "classifier.db-wal"
    shm = data_dir / "classifier.db-shm"
    db.write_text("fake db")
    wal.write_text("wal")
    shm.write_text("shm")
    return db, wal, shm


def test_reset_delegates_to_laravel_postgres(data_dir: Path) -> None:
    """The Python CLI keeps the command but runs the Laravel/PostgreSQL reset."""
    cmd_reset(include_config=False)

    from app.cli import subprocess as patched_subprocess

    patched_subprocess.run.assert_called_once()
    args = patched_subprocess.run.call_args.args[0]
    assert args[-2:] == ["archibot:reset", "--yes"]


def test_reset_deletes_legacy_sqlite_files_after_laravel_reset(data_dir: Path) -> None:
    """Legacy SQLite files are cleanup only, not the canonical reset target."""
    db, wal, shm = _create_db_files(data_dir)

    cmd_reset(include_config=False)

    assert not db.exists()
    assert not wal.exists()
    assert not shm.exists()


def test_reset_include_config(data_dir: Path) -> None:
    """config.env and backup files are deleted when --include-config is set."""
    _create_db_files(data_dir)
    config_env = data_dir / "config.env"
    config_env.write_text("OLLAMA_URL=http://test")
    bak1 = data_dir / "config.bak.20240101120000"
    bak1.write_text("old")
    bak2 = data_dir / "config.bak.20240315090000"
    bak2.write_text("older")

    cmd_reset(include_config=True)

    from app.cli import subprocess as patched_subprocess

    args = patched_subprocess.run.call_args.args[0]
    assert "--include-config" in args
    assert not config_env.exists()
    assert not bak1.exists()
    assert not bak2.exists()


def test_reset_without_include_config_keeps_env(data_dir: Path) -> None:
    """config.env is preserved when --include-config is not set."""
    _create_db_files(data_dir)
    config_env = data_dir / "config.env"
    config_env.write_text("OLLAMA_URL=http://test")

    cmd_reset(include_config=False)

    assert config_env.exists()


def test_reset_stops_when_laravel_reset_fails(data_dir: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    """Legacy cleanup does not run when the canonical Laravel reset fails."""
    db, _, _ = _create_db_files(data_dir)
    monkeypatch.setattr(
        "app.cli.subprocess.run",
        MagicMock(return_value=subprocess.CompletedProcess(args=[], returncode=2)),
    )

    with pytest.raises(SystemExit, match="2"):
        cmd_reset(include_config=False)

    assert db.exists()


def test_reset_requires_laravel_artisan(monkeypatch: pytest.MonkeyPatch) -> None:
    """The CLI must not silently fall back to the legacy SQLite reset."""
    monkeypatch.setattr("app.cli._laravel_artisan_path", lambda: None)

    with pytest.raises(SystemExit, match="1"):
        cmd_reset(include_config=False)


def test_reset_requires_yes_flag(monkeypatch: pytest.MonkeyPatch) -> None:
    """main() exits with error when --yes is missing."""
    monkeypatch.setattr(sys, "argv", ["cli", "reset"])
    monkeypatch.setattr("app.cli._configure_logging", MagicMock())

    from app.cli import main

    with pytest.raises(SystemExit, match="1"):
        main()
