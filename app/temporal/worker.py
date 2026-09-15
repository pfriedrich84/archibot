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
from app.temporal.document_phase_activities import (
    finish_document_review,
    process_document_classification_phase,
    process_document_embedding_phase,
    process_document_judge_phase,
    process_document_ocr_phase,
    publish_document_review,
    select_document_ocr_phase,
)
from app.temporal.embedding_activities import (
    embed_document,
    fail_embedding_preparation,
    finish_embedding_generation,
    prepare_embedding_generation,
    project_embedding_progress,
)
from app.temporal.names import (
    CLASSIFICATION_TASK_QUEUE,
    EMBEDDING_TASK_QUEUE,
    JUDGE_TASK_QUEUE,
    OCR_TEXT_TASK_QUEUE,
    OCR_VISION_TASK_QUEUE,
    PAPERLESS_TASK_QUEUE,
)
from app.temporal.phase_activities import (
    embedding_index_status,
    load_model_phase_configuration,
    project_model_phase,
)
from app.temporal.review_activities import commit_review_suggestion, fail_review_commit
from app.temporal.workflows import (
    DocumentWorkflow,
    EmbeddingIndexWorkflow,
    ModelPhaseSchedulerWorkflow,
    PollReconciliationWorkflow,
    ReviewCommitWorkflow,
    RuntimeProbeWorkflow,
)


async def run_worker() -> None:
    """Poll the orchestration task queue until the process is terminated."""
    client = await connect_temporal()
    control_worker = Worker(
        client,
        task_queue=settings.temporal_task_queue,
        workflows=[
            RuntimeProbeWorkflow,
            EmbeddingIndexWorkflow,
            PollReconciliationWorkflow,
            DocumentWorkflow,
            ReviewCommitWorkflow,
            ModelPhaseSchedulerWorkflow,
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
            fail_document_processing,
            finish_document_review,
            check_document_readiness,
            process_document_for_review,
            fail_review_commit,
            load_model_phase_configuration,
            embedding_index_status,
            project_model_phase,
        ],
    )
    activity_workers = [
        Worker(
            client,
            task_queue=EMBEDDING_TASK_QUEUE,
            activities=[embed_document, process_document_embedding_phase],
        ),
        Worker(
            client,
            task_queue=OCR_TEXT_TASK_QUEUE,
            activities=[process_document_ocr_phase],
        ),
        Worker(
            client,
            task_queue=OCR_VISION_TASK_QUEUE,
            activities=[process_document_ocr_phase],
        ),
        Worker(
            client,
            task_queue=CLASSIFICATION_TASK_QUEUE,
            activities=[process_document_classification_phase],
        ),
        Worker(
            client,
            task_queue=JUDGE_TASK_QUEUE,
            activities=[process_document_judge_phase],
        ),
        Worker(
            client,
            task_queue=PAPERLESS_TASK_QUEUE,
            activities=[
                publish_document_review,
                select_document_ocr_phase,
                commit_review_suggestion,
            ],
        ),
    ]
    logging.getLogger(__name__).info(
        "Temporal worker connected namespace=%s task_queue=%s",
        settings.temporal_namespace,
        settings.temporal_task_queue,
    )
    await asyncio.gather(control_worker.run(), *(worker.run() for worker in activity_workers))


def main() -> None:
    """Run the Temporal worker under the container supervisor."""
    logging.basicConfig(level=getattr(logging, settings.log_level.upper(), logging.INFO))
    asyncio.run(run_worker())


if __name__ == "__main__":
    main()
