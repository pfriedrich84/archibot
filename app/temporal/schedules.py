"""Reconcile Temporal-owned recurring schedules with ArchiBot settings."""

from __future__ import annotations

import asyncio
from datetime import timedelta
from typing import Protocol

from temporalio.client import (
    Schedule,
    ScheduleActionStartWorkflow,
    ScheduleAlreadyRunningError,
    ScheduleIntervalSpec,
    ScheduleOverlapPolicy,
    SchedulePolicy,
    ScheduleSpec,
    ScheduleState,
    ScheduleUpdate,
)

from app.config import settings
from app.temporal.document_activities import poll_interval_seconds
from app.temporal.names import (
    POLL_RECONCILIATION_SCHEDULE_ID,
    SCHEDULED_POLL_RECONCILIATION_WORKFLOW,
)


class ScheduleClient(Protocol):
    async def create_schedule(self, schedule_id: str, schedule: Schedule): ...

    def get_schedule_handle(self, schedule_id: str): ...


def poll_reconciliation_schedule(interval_seconds: int) -> Schedule:
    """Build the authoritative scheduled reconciliation definition."""
    paused = interval_seconds <= 0
    every = timedelta(seconds=interval_seconds if interval_seconds > 0 else 600)
    return Schedule(
        action=ScheduleActionStartWorkflow(
            SCHEDULED_POLL_RECONCILIATION_WORKFLOW,
            id=f"{POLL_RECONCILIATION_SCHEDULE_ID}/run",
            task_queue=settings.temporal_task_queue,
            static_summary="ArchiBot inbox reconciliation",
            static_details="Discovers Inbox documents missed by Paperless webhooks.",
        ),
        spec=ScheduleSpec(intervals=[ScheduleIntervalSpec(every=every)]),
        policy=SchedulePolicy(
            overlap=ScheduleOverlapPolicy.SKIP,
            catchup_window=timedelta(minutes=1),
            pause_on_failure=False,
        ),
        state=ScheduleState(
            paused=paused,
            note=(
                "Disabled because POLL_INTERVAL_SECONDS is 0."
                if paused
                else f"Reconcile every {interval_seconds} seconds."
            ),
        ),
    )


def _schedule_matches(current: Schedule, desired: Schedule) -> bool:
    """Compare persisted semantics without encoded display metadata."""
    current_action = current.action
    desired_action = desired.action
    return (
        isinstance(current_action, ScheduleActionStartWorkflow)
        and isinstance(desired_action, ScheduleActionStartWorkflow)
        and current_action.workflow == desired_action.workflow
        and current_action.id == desired_action.id
        and current_action.task_queue == desired_action.task_queue
        and current.spec == desired.spec
        and current.policy == desired.policy
        and current.state == desired.state
    )


async def reconcile_poll_schedule(client: ScheduleClient) -> int:
    """Create or update the singleton Temporal reconciliation schedule."""
    interval = await asyncio.to_thread(poll_interval_seconds)
    desired = poll_reconciliation_schedule(interval)
    try:
        await client.create_schedule(POLL_RECONCILIATION_SCHEDULE_ID, desired)
    except ScheduleAlreadyRunningError:
        handle = client.get_schedule_handle(POLL_RECONCILIATION_SCHEDULE_ID)
        description = await handle.describe()
        if not _schedule_matches(description.schedule, desired):
            await handle.update(lambda _input: ScheduleUpdate(schedule=desired))
    return interval
