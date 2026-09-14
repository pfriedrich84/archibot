"""Deliver transactional PostgreSQL outbox intents to Temporal."""

from __future__ import annotations

import asyncio
import logging
from typing import Any, Protocol

from temporalio.client import Client
from temporalio.common import WorkflowIDReusePolicy
from temporalio.exceptions import WorkflowAlreadyStartedError

from app.config import settings
from app.temporal.client import connect_temporal
from app.temporal.outbox import OutboxIntent, claim_next_intent, mark_delivered, mark_failed

log = logging.getLogger(__name__)


class TemporalClient(Protocol):
    """Client operations used by the relay and its isolated tests."""

    async def start_workflow(self, workflow: str, arg: Any, **kwargs: Any) -> Any: ...

    def get_workflow_handle(self, workflow_id: str) -> Any: ...


async def deliver_intent(client: TemporalClient, intent: OutboxIntent) -> None:
    """Deliver one claimed operation using stable cross-restart identities."""
    if intent.operation == "start_workflow":
        if not intent.workflow_type or not intent.task_queue:
            raise ValueError("start_workflow intent requires workflow_type and task_queue")
        try:
            await client.start_workflow(
                intent.workflow_type,
                intent.payload,
                id=intent.workflow_id,
                task_queue=intent.task_queue,
                id_reuse_policy=WorkflowIDReusePolicy.REJECT_DUPLICATE,
            )
        except WorkflowAlreadyStartedError:
            # A relay may crash after Temporal accepted the start but before the
            # database acknowledgement. The immutable workflow ID makes replay safe.
            return
        return

    handle = client.get_workflow_handle(intent.workflow_id)
    if intent.operation == "signal_workflow":
        if not intent.signal_name:
            raise ValueError("signal_workflow intent requires signal_name")
        await handle.signal(
            intent.signal_name,
            {"intent_id": intent.intent_key, "payload": intent.payload},
        )
        return
    if intent.operation == "cancel_workflow":
        await handle.cancel()
        return
    raise ValueError(f"Unsupported Temporal outbox operation: {intent.operation}")


async def relay_forever(client: Client | None = None) -> None:
    """Poll and deliver intents until the supervised process is terminated."""
    temporal = client or await connect_temporal()
    while True:
        intent = await asyncio.to_thread(
            claim_next_intent,
            settings.temporal_outbox_lease_seconds,
        )
        if intent is None:
            await asyncio.sleep(settings.temporal_outbox_poll_seconds)
            continue

        try:
            await deliver_intent(temporal, intent)
        except Exception as exc:
            log.warning(
                "Temporal outbox delivery failed intent_id=%s operation=%s attempts=%s error_type=%s",
                intent.id,
                intent.operation,
                intent.attempts,
                type(exc).__name__,
            )
            await asyncio.to_thread(
                mark_failed,
                intent,
                f"{type(exc).__name__}: {exc}",
                settings.temporal_outbox_max_attempts,
            )
        else:
            await asyncio.to_thread(mark_delivered, intent.id)


def main() -> None:
    """Run the outbox relay under the container supervisor."""
    logging.basicConfig(level=getattr(logging, settings.log_level.upper(), logging.INFO))
    asyncio.run(relay_forever())


if __name__ == "__main__":
    main()
