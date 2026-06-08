from __future__ import annotations

import pytest

from app.actor_runner import ActorRunnerError, run_reindex_ocr_command
from app.jobs.commands import CommandRecord


def test_run_reindex_ocr_command_uses_durable_payload(monkeypatch: pytest.MonkeyPatch) -> None:
    statuses: list[tuple[int, str, str | None]] = []
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
        "app.actor_runner.mark_command_status",
        lambda command_id, status, error=None: statuses.append((command_id, status, error)),
    )
    monkeypatch.setattr(
        "app.actor_runner._reindex_ocr_documents_impl",
        lambda **kwargs: calls.append(kwargs),
    )

    run_reindex_ocr_command(42)

    assert statuses == [(42, "running", None), (42, "succeeded", None)]
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
