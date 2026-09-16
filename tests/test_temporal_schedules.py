from __future__ import annotations

from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest
from temporalio.client import ScheduleAlreadyRunningError, ScheduleOverlapPolicy

from app.temporal import schedules


def test_poll_schedule_is_serial_and_can_be_paused():
    active = schedules.poll_reconciliation_schedule(600)
    paused = schedules.poll_reconciliation_schedule(0)

    assert active.spec.intervals[0].every.total_seconds() == 600
    assert active.policy.overlap is ScheduleOverlapPolicy.SKIP
    assert active.state.paused is False
    assert paused.state.paused is True


def test_schedule_comparison_ignores_encoded_display_metadata():
    current = schedules.poll_reconciliation_schedule(600)
    desired = schedules.poll_reconciliation_schedule(600)
    current.action.static_summary = object()
    current.action.static_details = object()

    assert schedules._schedule_matches(current, desired) is True


@pytest.mark.asyncio
async def test_schedule_reconciliation_updates_changed_interval(monkeypatch):
    current = schedules.poll_reconciliation_schedule(600)
    handle = Mock()
    handle.describe = AsyncMock(return_value=SimpleNamespace(schedule=current))
    handle.update = AsyncMock()
    client = Mock()
    client.create_schedule = AsyncMock(side_effect=ScheduleAlreadyRunningError())
    client.get_schedule_handle.return_value = handle
    monkeypatch.setattr(schedules, "poll_interval_seconds", lambda: 1800)

    interval = await schedules.reconcile_poll_schedule(client)

    assert interval == 1800
    handle.update.assert_awaited_once()
    update = handle.update.await_args.args[0](SimpleNamespace())
    assert update.schedule.spec.intervals[0].every.total_seconds() == 1800


@pytest.mark.asyncio
async def test_schedule_reconciliation_does_not_rewrite_matching_schedule(monkeypatch):
    current = schedules.poll_reconciliation_schedule(600)
    handle = Mock()
    handle.describe = AsyncMock(return_value=SimpleNamespace(schedule=current))
    handle.update = AsyncMock()
    client = Mock()
    client.create_schedule = AsyncMock(side_effect=ScheduleAlreadyRunningError())
    client.get_schedule_handle.return_value = handle
    monkeypatch.setattr(schedules, "poll_interval_seconds", lambda: 600)

    await schedules.reconcile_poll_schedule(client)

    handle.update.assert_not_awaited()
