from __future__ import annotations

import pytest

from app.actor_runner import ActorRunnerError, run_reindex_ocr_command
from app.execution_lifecycle import DomainOutcome, DomainStatus
from app.jobs.commands import CommandRecord


def test_run_reindex_ocr_command_uses_durable_payload(monkeypatch: pytest.MonkeyPatch) -> None:
    calls: list[dict[str, object]] = []

    monkeypatch.setattr(
        "app.actor_runner.load_command",
        lambda command_id: CommandRecord(
            id=command_id,
            type="reindex_ocr",
            status="queued",
            payload={"limit": "7", "force": "1"},
        ),
    )
    monkeypatch.setattr(
        "app.actor_runner._reindex_ocr_documents_impl",
        lambda **kwargs: calls.append(kwargs),
    )
    monkeypatch.setattr(
        "app.actor_runner.outcome_for_source",
        lambda **kwargs: DomainOutcome(DomainStatus.SUCCEEDED, "reindex_ocr", "command", 42, 9, 1),
    )

    run_reindex_ocr_command(42)

    assert calls == [{"command_id": 42, "limit": 7, "force": True}]


def test_run_reindex_ocr_command_rejects_wrong_command_type(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(
        "app.actor_runner.load_command",
        lambda command_id: CommandRecord(
            id=command_id,
            type="reindex",
            status="queued",
            payload={},
        ),
    )

    with pytest.raises(ActorRunnerError, match="expected 'reindex_ocr'"):
        run_reindex_ocr_command(42)
