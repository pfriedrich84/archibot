from __future__ import annotations

from contextlib import nullcontext
from dataclasses import replace
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest
from temporalio.common import WorkflowIDReusePolicy

from app.temporal import outbox
from app.temporal.outbox import OutboxIntent
from app.temporal.relay import deliver_intent


def intent(**changes) -> OutboxIntent:
    base = OutboxIntent(
        id=7,
        intent_key="6a45dd09-652d-4387-8634-9755db7f0f98",
        operation="start_workflow",
        workflow_id="archibot/runtime-probe/6a45dd09-652d-4387-8634-9755db7f0f98",
        workflow_type="archibot.runtime_probe",
        task_queue="archibot-orchestration",
        signal_name=None,
        payload={"request_id": "6a45dd09-652d-4387-8634-9755db7f0f98"},
        attempts=1,
    )
    return replace(base, **changes)


@pytest.mark.asyncio
async def test_start_intent_uses_stable_identity_and_rejects_duplicate_runs():
    client = Mock()
    client.start_workflow = AsyncMock()

    value = intent()
    await deliver_intent(client, value)

    client.start_workflow.assert_awaited_once_with(
        value.workflow_type,
        value.payload,
        id=value.workflow_id,
        task_queue=value.task_queue,
        id_reuse_policy=WorkflowIDReusePolicy.REJECT_DUPLICATE,
    )


@pytest.mark.asyncio
async def test_signal_intent_carries_idempotency_key_for_workflow_deduplication():
    handle = Mock()
    handle.signal = AsyncMock()
    client = Mock()
    client.get_workflow_handle.return_value = handle
    value = intent(operation="signal_workflow", signal_name="review.accepted")

    await deliver_intent(client, value)

    handle.signal.assert_awaited_once_with(
        "review.accepted",
        {"intent_id": value.intent_key, "payload": value.payload},
    )


@pytest.mark.asyncio
async def test_cancel_intent_targets_stable_workflow_id():
    handle = Mock()
    handle.cancel = AsyncMock()
    client = Mock()
    client.get_workflow_handle.return_value = handle
    value = intent(operation="cancel_workflow", workflow_type=None, task_queue=None)

    await deliver_intent(client, value)

    handle.cancel.assert_awaited_once_with()


@pytest.mark.asyncio
async def test_invalid_intent_fails_closed():
    with pytest.raises(ValueError, match="Unsupported"):
        await deliver_intent(Mock(), intent(operation="unknown"))


def test_exhausted_embedding_start_terminalizes_its_temporal_command(monkeypatch):
    calls = []
    connection = Mock()
    connection.execute.side_effect = lambda statement, params=None: (
        calls.append((statement, params or {})) or SimpleNamespace(rowcount=1)
    )
    database = Mock()
    database.begin.return_value = nullcontext(connection)
    monkeypatch.setattr(outbox, "engine", lambda: database)
    monkeypatch.setattr(outbox, "sql_text", lambda statement: statement)
    value = intent(
        workflow_id="archibot/embedding-index/9",
        workflow_type="archibot.embedding_index",
        payload={"command_id": 9},
        attempts=20,
    )

    outbox.mark_failed(value, "connection refused", max_attempts=20)

    assert len(calls) == 2
    assert calls[0][1]["status"] == "dead_letter"
    assert "UPDATE commands" in calls[1][0]
    assert "orchestration_driver' = 'temporal'" in calls[1][0]
    assert calls[1][1] == {
        "command_id": 9,
        "workflow_id": "archibot/embedding-index/9",
    }


def test_transient_outbox_failure_does_not_terminalize_command(monkeypatch):
    calls = []
    connection = Mock()
    connection.execute.side_effect = lambda statement, params=None: (
        calls.append((statement, params or {})) or SimpleNamespace(rowcount=1)
    )
    database = Mock()
    database.begin.return_value = nullcontext(connection)
    monkeypatch.setattr(outbox, "engine", lambda: database)
    monkeypatch.setattr(outbox, "sql_text", lambda statement: statement)

    outbox.mark_failed(intent(payload={"command_id": 9}, attempts=1), "timeout", 20)

    assert len(calls) == 1
    assert calls[0][1]["status"] == "pending"
