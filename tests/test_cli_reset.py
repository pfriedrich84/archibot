from __future__ import annotations

import subprocess
import sys
from pathlib import Path
from unittest.mock import MagicMock

import pytest

from app import cli


def test_reset_delegates_only_to_laravel_postgres(monkeypatch, tmp_path: Path, capsys) -> None:
    artisan = tmp_path / "laravel" / "artisan"
    artisan.parent.mkdir()
    artisan.write_text("<?php")
    run = MagicMock(return_value=subprocess.CompletedProcess(args=[], returncode=0))
    monkeypatch.setattr(cli, "_laravel_artisan_path", lambda: artisan)
    monkeypatch.setattr(cli.subprocess, "run", run)

    cli.cmd_reset(include_config=True)

    assert run.call_args.args[0] == [
        "php",
        str(artisan),
        "archibot:reset",
        "--yes",
        "--include-config",
    ]
    output = capsys.readouterr()
    assert "classifier.db" not in output.out + output.err


def test_reset_requires_confirmation(monkeypatch) -> None:
    monkeypatch.setattr(cli, "_configure_logging", MagicMock())
    monkeypatch.setattr(sys, "argv", ["archibot", "reset"])
    with pytest.raises(SystemExit, match="1"):
        cli.main()
