"""Deterministic Temporal workflows."""

from __future__ import annotations

from contextlib import suppress
from dataclasses import dataclass
from datetime import timedelta

from temporalio import workflow
from temporalio.common import RetryPolicy, WorkflowIDReusePolicy
from temporalio.exceptions import ActivityError, WorkflowAlreadyStartedError

with workflow.unsafe.imports_passed_through():
    from app.temporal.contracts import (
        DocumentWorkflowRequest,
        DocumentWorkflowResult,
        EmbeddingPreparationFailure,
        EmbeddingProgress,
        EmbeddingWorkflowRequest,
        EmbeddingWorkflowResult,
        EmbedDocumentRequest,
        PollWorkflowRequest,
        PollWorkflowResult,
    )
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

from app.temporal.names import (
    DOCUMENT_WORKFLOW,
    EMBEDDING_INDEX_WORKFLOW,
    POLL_RECONCILIATION_WORKFLOW,
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


@workflow.defn(name=DOCUMENT_WORKFLOW)
class DocumentWorkflow:
    """Own one Paperless document content version through its review decision."""

    def __init__(self) -> None:
        self._review_decision: dict[str, object] | None = None

    @workflow.signal(name="review_decision")
    def review_decision(self, decision: dict[str, object]) -> None:
        self._review_decision = decision

    @workflow.run
    async def run(self, request: DocumentWorkflowRequest) -> DocumentWorkflowResult:
        while True:
            readiness = await workflow.execute_activity(
                check_document_readiness,
                request.pipeline_run_id,
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=0),
            )
            if readiness.status == "complete":
                suggestion_id = readiness.review_suggestion_id
                break
            if readiness.status == "cancelled":
                return DocumentWorkflowResult(request.pipeline_run_id, None, "cancelled")
            if readiness.status == "ready":
                try:
                    result = await workflow.execute_activity(
                        process_document_for_review,
                        request.pipeline_run_id,
                        schedule_to_close_timeout=timedelta(hours=24),
                        start_to_close_timeout=timedelta(hours=2),
                        heartbeat_timeout=timedelta(minutes=2),
                        retry_policy=RetryPolicy(maximum_attempts=5),
                    )
                except ActivityError:
                    await workflow.execute_activity(
                        fail_document_processing,
                        request.pipeline_run_id,
                        start_to_close_timeout=timedelta(minutes=1),
                        retry_policy=RetryPolicy(maximum_attempts=0),
                    )
                    raise
                suggestion_id = result.review_suggestion_id
                break
            await workflow.sleep(timedelta(seconds=30))

        await workflow.wait_condition(lambda: self._review_decision is not None)
        return DocumentWorkflowResult(request.pipeline_run_id, suggestion_id, "review_decided")


@workflow.defn(name=POLL_RECONCILIATION_WORKFLOW)
class PollReconciliationWorkflow:
    """Discover inbox identities and detach their independent document workflows."""

    @workflow.run
    async def run(self, request: PollWorkflowRequest) -> PollWorkflowResult:
        try:
            discovery = await workflow.execute_activity(
                discover_inbox_documents,
                request,
                schedule_to_close_timeout=timedelta(hours=6),
                start_to_close_timeout=timedelta(minutes=30),
                heartbeat_timeout=timedelta(minutes=2),
                retry_policy=RetryPolicy(
                    maximum_attempts=5,
                    non_retryable_error_types=["ValueError"],
                ),
            )
        except ActivityError:
            await workflow.execute_activity(
                fail_poll_discovery,
                request.command_id,
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=0),
            )
            raise

        started = 0
        for child in discovery.workflow_starts:
            with suppress(WorkflowAlreadyStartedError):
                await workflow.start_child_workflow(
                    DocumentWorkflow.run,
                    DocumentWorkflowRequest(child.pipeline_run_id),
                    id=child.workflow_id,
                    parent_close_policy=workflow.ParentClosePolicy.ABANDON,
                    id_reuse_policy=WorkflowIDReusePolicy.REJECT_DUPLICATE,
                )
            started += 1

        return await workflow.execute_activity(
            finish_poll_discovery,
            PollWorkflowResult(
                command_id=request.command_id,
                documents_seen=discovery.documents_seen,
                documents_started=started,
                documents_skipped=discovery.documents_skipped,
                status=discovery.status,
            ),
            start_to_close_timeout=timedelta(minutes=1),
            retry_policy=RetryPolicy(maximum_attempts=0),
        )
