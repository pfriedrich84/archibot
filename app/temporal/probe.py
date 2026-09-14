"""Operator command for a side-effect-free Temporal round trip."""

from __future__ import annotations

import argparse
import asyncio
import json
from uuid import uuid4

from temporalio.common import WorkflowIDReusePolicy

from app.config import settings
from app.temporal.client import connect_temporal
from app.temporal.names import runtime_probe_workflow_id
from app.temporal.workflows import RuntimeProbeRequest, RuntimeProbeWorkflow


async def execute_probe(request_id: str) -> dict[str, int | str]:
    """Execute and return a JSON-safe Temporal probe result."""
    client = await connect_temporal()
    result = await client.execute_workflow(
        RuntimeProbeWorkflow.run,
        RuntimeProbeRequest(request_id=request_id),
        id=runtime_probe_workflow_id(request_id),
        task_queue=settings.temporal_task_queue,
        id_reuse_policy=WorkflowIDReusePolicy.ALLOW_DUPLICATE_FAILED_ONLY,
    )
    return {
        "request_id": result.request_id,
        "protocol_version": result.protocol_version,
    }


def main() -> None:
    """Execute one probe and print its bounded result."""
    parser = argparse.ArgumentParser(description="Verify the ArchiBot Temporal runtime")
    parser.add_argument("--request-id", default=None)
    args = parser.parse_args()
    request_id = args.request_id or str(uuid4())
    print(json.dumps(asyncio.run(execute_probe(request_id)), sort_keys=True))


if __name__ == "__main__":
    main()
