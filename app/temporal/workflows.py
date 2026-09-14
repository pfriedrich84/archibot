"""Deterministic Temporal workflows."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import timedelta

from temporalio import workflow
from temporalio.common import RetryPolicy
from temporalio.exceptions import ActivityError

with workflow.unsafe.imports_passed_through():
    from app.temporal.contracts import (
        EmbeddingPreparationFailure,
        EmbeddingProgress,
        EmbeddingWorkflowRequest,
        EmbeddingWorkflowResult,
        EmbedDocumentRequest,
    )
    from app.temporal.embedding_activities import (
        embed_document,
        fail_embedding_preparation,
        finish_embedding_generation,
        prepare_embedding_generation,
        project_embedding_progress,
    )

from app.temporal.names import (
    EMBEDDING_INDEX_WORKFLOW,
    RUNTIME_PROBE_WORKFLOW,
    WORKFLOW_PROTOCOL_VERSION,
)


@dataclass(frozen=True)
class RuntimeProbeRequest:
    """Input for the side-effect-free runtime probe."""

    request_id: str


@dataclass(frozen=True)
class RuntimeProbeResult:
    """Result returned after a worker has replayed the probe workflow."""

    request_id: str
    protocol_version: int


@workflow.defn(name=RUNTIME_PROBE_WORKFLOW)
class RuntimeProbeWorkflow:
    """Prove Temporal server/worker execution without product side effects."""

    @workflow.run
    async def run(self, request: RuntimeProbeRequest) -> RuntimeProbeResult:
        return RuntimeProbeResult(
            request_id=request.request_id,
            protocol_version=WORKFLOW_PROTOCOL_VERSION,
        )


@workflow.defn(name=EMBEDDING_INDEX_WORKFLOW)
class EmbeddingIndexWorkflow:
    """Build one generation without retaining documents in workflow history."""

    @workflow.run
    async def run(self, request: EmbeddingWorkflowRequest) -> EmbeddingWorkflowResult:
        try:
            prepared = await workflow.execute_activity(
                prepare_embedding_generation,
                request,
                schedule_to_close_timeout=timedelta(hours=24),
                start_to_close_timeout=timedelta(minutes=30),
                heartbeat_timeout=timedelta(minutes=2),
                retry_policy=RetryPolicy(
                    maximum_attempts=0,
                    maximum_interval=timedelta(minutes=1),
                    non_retryable_error_types=["ValueError"],
                ),
            )
        except ActivityError:
            await workflow.execute_activity(
                fail_embedding_preparation,
                EmbeddingPreparationFailure(
                    command_id=request.command_id,
                    error="Temporal embedding preparation exhausted its retries.",
                ),
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=0),
            )
            raise
        total = len(prepared.document_ids)
        embedded = 0
        failed = 0
        for document_id in prepared.document_ids:
            try:
                result = await workflow.execute_activity(
                    embed_document,
                    EmbedDocumentRequest(prepared.build_id, document_id),
                    start_to_close_timeout=timedelta(minutes=30),
                    heartbeat_timeout=timedelta(minutes=2),
                    retry_policy=RetryPolicy(maximum_attempts=5),
                )
                if result.status == "embedded":
                    embedded += 1
                else:
                    # The document changed after preparation and no longer belongs
                    # to the trusted embedding population.
                    total -= 1
            except ActivityError:
                failed += 1
            progress = EmbeddingProgress(
                command_id=prepared.command_id,
                build_id=prepared.build_id,
                total=total,
                done=embedded + failed,
                embedded=embedded,
                failed=failed,
            )
            await workflow.execute_activity(
                project_embedding_progress,
                progress,
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=0),
            )

        return await workflow.execute_activity(
            finish_embedding_generation,
            EmbeddingProgress(
                command_id=prepared.command_id,
                build_id=prepared.build_id,
                total=total,
                done=embedded + failed,
                embedded=embedded,
                failed=failed,
            ),
            start_to_close_timeout=timedelta(minutes=1),
            retry_policy=RetryPolicy(maximum_attempts=0),
        )
