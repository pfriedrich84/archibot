from __future__ import annotations

from dataclasses import replace
from unittest.mock import AsyncMock, Mock

import pytest
from temporalio.common import WorkflowIDReusePolicy

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
