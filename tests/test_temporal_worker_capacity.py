from __future__ import annotations

import asyncio
import inspect

import pytest

from app.temporal import worker
from app.temporal.model_capacity import serialized_model_activity


def test_model_activities_share_one_single_slot_worker():
    source = inspect.getsource(worker.run_worker)

    assert worker.MODEL_ACTIVITY_CONCURRENCY == 1
    assert source.count("task_queue=MODEL_TASK_QUEUE") == 1
    assert "max_concurrent_activities=MODEL_ACTIVITY_CONCURRENCY" in source


@pytest.mark.asyncio
async def test_model_activity_guard_serializes_concurrent_calls():
    active = 0
    peak = 0

    @serialized_model_activity
    async def guarded(value: int) -> int:
        nonlocal active, peak
        active += 1
        peak = max(peak, active)
        await asyncio.sleep(0)
        active -= 1
        return value

    assert await asyncio.gather(guarded(1), guarded(2)) == [1, 2]
    assert peak == 1
