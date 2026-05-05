"""Tests for Laravel worker JSON CLI contract."""

from __future__ import annotations

import json
import sys
from pathlib import Path
from unittest.mock import AsyncMock, MagicMock, patch

import pytest


@pytest.fixture(autouse=True)
def _mock_cli_side_effects(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr("app.cli.init_db", MagicMock())
    monkeypatch.setattr("app.cli._configure_logging", MagicMock())


def test_main_poll_reads_worker_contract_and_writes_output(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    input_path = tmp_path / "input.json"
    output_path = tmp_path / "output.json"
    input_path.write_text(
        json.dumps({"id": 12, "type": "poll", "payload": {"force": True}}),
        encoding="utf-8",
    )
    monkeypatch.setattr(
        sys, "argv", ["cli", "poll", "--input", str(input_path), "--output", str(output_path)]
    )

    mock_cmd = AsyncMock()

    with patch("app.cli.COMMANDS", {"poll": ("desc", mock_cmd)}):
        from app.cli import main

        main()

    mock_cmd.assert_called_once_with(force=True)
    assert json.loads(output_path.read_text(encoding="utf-8")) == {
        "ok": True,
        "command": "poll",
        "job_id": 12,
        "type": "poll",
    }


def test_main_process_document_reads_document_id_from_worker_payload(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    input_path = tmp_path / "input.json"
    output_path = tmp_path / "output.json"
    input_path.write_text(
        json.dumps(
            {"id": 13, "type": "process_document", "payload": {"paperless_document_id": 224}}
        ),
        encoding="utf-8",
    )
    monkeypatch.setattr(
        sys,
        "argv",
        ["cli", "process-document", "--input", str(input_path), "--output", str(output_path)],
    )

    mock_cmd = AsyncMock()

    with patch("app.cli.COMMANDS", {"process-document": ("desc", mock_cmd)}):
        from app.cli import main

        main()

    mock_cmd.assert_called_once_with(224, force=False)
    payload = json.loads(output_path.read_text(encoding="utf-8"))
    assert payload["ok"] is True
    assert payload["command"] == "process-document"


def test_main_worker_contract_writes_failure_output(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    input_path = tmp_path / "input.json"
    output_path = tmp_path / "output.json"
    input_path.write_text(json.dumps({"id": 14, "type": "poll", "payload": {}}), encoding="utf-8")
    monkeypatch.setattr(
        sys, "argv", ["cli", "poll", "--input", str(input_path), "--output", str(output_path)]
    )

    mock_cmd = AsyncMock(side_effect=RuntimeError("boom"))

    with patch("app.cli.COMMANDS", {"poll": ("desc", mock_cmd)}):
        from app.cli import main

        with pytest.raises(RuntimeError, match="boom"):
            main()

    payload = json.loads(output_path.read_text(encoding="utf-8"))
    assert payload == {"ok": False, "command": "poll", "error": "boom"}
