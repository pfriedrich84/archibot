"""Supervised Temporal worker entry point."""

from __future__ import annotations

import asyncio
import logging

from temporalio.worker import Worker

from app.config import settings
from app.temporal.client import connect_temporal
from app.temporal.document_activities import (
    check_document_readiness,
    discover_inbox_documents,
    fail_document_processing,
    fail_poll_discovery,
    finish_poll_discovery,
    process_document_for_review,
)
from app.temporal.embedding_activities import (
    embed_document,
    fail_embedding_preparation,
    finish_embedding_generation,
    prepare_embedding_generation,
    project_embedding_progress,
)
from app.temporal.workflows import (
    DocumentWorkflow,
    EmbeddingIndexWorkflow,
    PollReconciliationWorkflow,
    RuntimeProbeWorkflow,
)


async def run_worker() -> None:
    """Poll the orchestration task queue until the process is terminated."""
    client = await connect_temporal()
    worker = Worker(
        client,
        task_queue=settings.temporal_task_queue,
        workflows=[
            RuntimeProbeWorkflow,
            EmbeddingIndexWorkflow,
            PollReconciliationWorkflow,
            DocumentWorkflow,
        ],
        activities=[
            prepare_embedding_generation,
            embed_document,
            fail_embedding_preparation,
            project_embedding_progress,
            finish_embedding_generation,
            discover_inbox_documents,
            finish_poll_discovery,
            fail_poll_discovery,
            check_document_readiness,
            process_document_for_review,
            fail_document_processing,
        ],
    )
    logging.getLogger(__name__).info(
        "Temporal worker connected namespace=%s task_queue=%s",
        settings.temporal_namespace,
        settings.temporal_task_queue,
    )
    await worker.run()


def main() -> None:
    """Run the Temporal worker under the container supervisor."""
    logging.basicConfig(level=getattr(logging, settings.log_level.upper(), logging.INFO))
    asyncio.run(run_worker())


if __name__ == "__main__":
    main()
