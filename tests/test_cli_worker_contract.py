"""Tests for Laravel worker JSON CLI contract."""

from __future__ import annotations

import json
import sys
from pathlib import Path
from unittest.mock import AsyncMock, MagicMock, patch

import pytest

from app.models import SuggestionRow


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

    with (
        patch("app.cli.COMMANDS", {"poll": ("desc", mock_cmd)}),
        patch("app.cli._latest_suggestion_id", return_value=0),
        patch("app.cli._review_suggestion_payloads_since", return_value=[]),
    ):
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

    with (
        patch("app.cli.COMMANDS", {"process-document": ("desc", mock_cmd)}),
        patch("app.cli._latest_suggestion_id", return_value=0),
        patch("app.cli._review_suggestion_payloads_since", return_value=[]),
    ):
        from app.cli import main

        main()

    mock_cmd.assert_called_once_with(224, force=False)
    payload = json.loads(output_path.read_text(encoding="utf-8"))
    assert payload["ok"] is True
    assert payload["command"] == "process-document"


def test_process_document_worker_output_includes_review_suggestions(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    input_path = tmp_path / "input.json"
    output_path = tmp_path / "output.json"
    input_path.write_text(
        json.dumps(
            {"id": 15, "type": "process_document", "payload": {"paperless_document_id": 224}}
        ),
        encoding="utf-8",
    )
    monkeypatch.setattr(
        sys,
        "argv",
        ["cli", "process-doc", "--input", str(input_path), "--output", str(output_path)],
    )

    mock_cmd = AsyncMock(return_value="classified")
    mock_suggestion = {
        "paperless_document_id": 224,
        "status": "pending",
        "proposed": {"title": "Invoice May"},
    }

    with (
        patch("app.cli.COMMANDS", {"process-doc": ("desc", mock_cmd)}),
        patch("app.cli._latest_suggestion_id", return_value=4),
        patch("app.cli._review_suggestion_payloads_since", return_value=[mock_suggestion]),
    ):
        from app.cli import main

        main()

    payload = json.loads(output_path.read_text(encoding="utf-8"))
    assert payload["result"] == "classified"
    assert payload["review_suggestions"] == [mock_suggestion]


def test_poll_worker_output_includes_new_review_suggestions(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    input_path = tmp_path / "input.json"
    output_path = tmp_path / "output.json"
    input_path.write_text(
        json.dumps({"id": 16, "type": "poll", "payload": {}}),
        encoding="utf-8",
    )
    monkeypatch.setattr(
        sys, "argv", ["cli", "poll", "--input", str(input_path), "--output", str(output_path)]
    )

    mock_cmd = AsyncMock()
    mock_suggestion = {"paperless_document_id": 225, "proposed": {"title": "Batch doc"}}

    with (
        patch("app.cli.COMMANDS", {"poll": ("desc", mock_cmd)}),
        patch("app.cli._latest_suggestion_id", return_value=8),
        patch(
            "app.cli._review_suggestion_payloads_since", return_value=[mock_suggestion]
        ) as mapper,
    ):
        from app.cli import main

        main()

    mapper.assert_called_once_with(8)
    payload = json.loads(output_path.read_text(encoding="utf-8"))
    assert payload["ok"] is True
    assert payload["review_suggestions"] == [mock_suggestion]


def test_commit_review_worker_contract_reads_source_suggestion_id(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    input_path = tmp_path / "input.json"
    output_path = tmp_path / "output.json"
    input_path.write_text(
        json.dumps({"id": 17, "type": "commit_review", "payload": {"source_suggestion_id": 7}}),
        encoding="utf-8",
    )
    monkeypatch.setattr(
        sys,
        "argv",
        ["cli", "commit-review", "--input", str(input_path), "--output", str(output_path)],
    )

    mock_cmd = AsyncMock(return_value={"source_suggestion_id": 7, "committed": True})

    with patch("app.cli.COMMANDS", {"commit-review": ("desc", mock_cmd)}):
        from app.cli import main

        main()

    mock_cmd.assert_called_once_with(7)
    payload = json.loads(output_path.read_text(encoding="utf-8"))
    assert payload["ok"] is True
    assert payload["command"] == "commit-review"
    assert payload["result"] == {"source_suggestion_id": 7, "committed": True}


def test_suggestion_row_maps_to_laravel_review_ingestion_shape() -> None:
    from app.cli import _suggestion_row_to_review_suggestion

    row = SuggestionRow(
        id=7,
        document_id=224,
        created_at="2026-05-05T10:00:00Z",
        status="pending",
        confidence=91,
        reasoning="Looks like an invoice.",
        original_title="Scan 224",
        original_date="2026-05-01",
        original_correspondent=1,
        original_doctype=2,
        original_storage_path=3,
        original_tags_json="[4, 5]",
        proposed_title="Invoice May",
        proposed_date="2026-05-02",
        proposed_correspondent_name="ACME",
        proposed_correspondent_id=10,
        proposed_doctype_name="Invoice",
        proposed_doctype_id=11,
        proposed_storage_path_name="Archive",
        proposed_storage_path_id=12,
        proposed_tags_json='[{"id": 6, "name": "Accounting", "confidence": 88}]',
        raw_response='{"title": "Invoice May"}',
        context_docs_json='[{"id": 99, "title": "Old invoice", "distance": 0.1}]',
        judge_verdict="agree",
        judge_reasoning="Consistent.",
        original_proposed_json='{"title": "Scan 224"}',
    )

    payload = _suggestion_row_to_review_suggestion(row)

    assert payload == {
        "source_suggestion_id": 7,
        "python_suggestion_id": 7,
        "paperless_document_id": 224,
        "status": "pending",
        "confidence": 91,
        "reasoning": "Looks like an invoice.",
        "original": {
            "title": "Scan 224",
            "date": "2026-05-01",
            "correspondent_id": 1,
            "document_type_id": 2,
            "storage_path_id": 3,
            "tags": [4, 5],
        },
        "proposed": {
            "title": "Invoice May",
            "date": "2026-05-02",
            "correspondent_name": "ACME",
            "correspondent_id": 10,
            "document_type_name": "Invoice",
            "document_type_id": 11,
            "storage_path_name": "Archive",
            "storage_path_id": 12,
            "tags": [{"id": 6, "name": "Accounting", "confidence": 88}],
        },
        "context_documents": [{"id": 99, "title": "Old invoice", "distance": 0.1}],
        "raw_response": {"title": "Invoice May"},
        "judge_verdict": "agree",
        "judge_reasoning": "Consistent.",
        "original_proposed_snapshot": {"title": "Scan 224"},
    }


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

    with (
        patch("app.cli.COMMANDS", {"poll": ("desc", mock_cmd)}),
        patch("app.cli._latest_suggestion_id", return_value=0),
    ):
        from app.cli import main

        with pytest.raises(RuntimeError, match="boom"):
            main()

    payload = json.loads(output_path.read_text(encoding="utf-8"))
    assert payload == {"ok": False, "command": "poll", "error": "boom"}
