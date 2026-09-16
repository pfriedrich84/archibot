"""Deterministic Temporal workflows."""

from __future__ import annotations

from dataclasses import dataclass, replace
from datetime import timedelta
from typing import Any

from temporalio import workflow
from temporalio.common import RetryPolicy, SearchAttributeKey, WorkflowIDReusePolicy
from temporalio.exceptions import ActivityError, WorkflowAlreadyStartedError

with workflow.unsafe.imports_passed_through():
    from app.temporal.contracts import (
        DocumentPhaseRequest,
        DocumentReviewCompletion,
        DocumentSupersession,
        DocumentWorkflowRequest,
        DocumentWorkflowResult,
        EmbeddingPreparationFailure,
        EmbeddingProgress,
        EmbeddingWorkflowRequest,
        EmbeddingWorkflowResult,
        EmbedDocumentRequest,
        ModelPhaseConfiguration,
        PollWorkflowRequest,
        PollWorkflowResult,
        ReviewCommitRequest,
        ReviewCommitResult,
        ScheduledPollStart,
    )
    from app.temporal.document_activities import (
        check_document_readiness,
        create_scheduled_poll_command,
        discover_inbox_documents,
        fail_document_processing,
        fail_poll_discovery,
        finish_poll_discovery,
    )
    from app.temporal.document_phase_activities import (
        finish_document_review,
        process_document_classification_phase,
        process_document_embedding_phase,
        process_document_judge_phase,
        process_document_ocr_phase,
        publish_document_review,
        supersede_document_processing,
    )
    from app.temporal.embedding_activities import (
        embed_document,
        fail_embedding_preparation,
        finish_embedding_generation,
        prepare_embedding_generation,
        project_embedding_progress,
    )
    from app.temporal.phase_activities import (
        load_model_phase_configuration,
    )
    from app.temporal.review_activities import (
        commit_review_suggestion,
        fail_review_commit,
    )

from app.temporal.names import (
    CLASSIFICATION_TASK_QUEUE,
    DOCUMENT_WORKFLOW,
    EMBEDDING_INDEX_WORKFLOW,
    EMBEDDING_TASK_QUEUE,
    JUDGE_TASK_QUEUE,
    MODEL_TASK_QUEUE,
    OCR_TEXT_TASK_QUEUE,
    OCR_VISION_TASK_QUEUE,
    PAPERLESS_TASK_QUEUE,
    POLL_RECONCILIATION_WORKFLOW,
    REVIEW_COMMIT_WORKFLOW,
    RUNTIME_PROBE_WORKFLOW,
    SCHEDULED_POLL_RECONCILIATION_WORKFLOW,
    WORKFLOW_PROTOCOL_VERSION,
)

ARCHIBOT_PHASE = SearchAttributeKey.for_keyword("ArchiBotPhase")
ARCHIBOT_PIPELINE_RUN_ID = SearchAttributeKey.for_int("ArchiBotPipelineRunId")
ARCHIBOT_DOCUMENT_ID = SearchAttributeKey.for_int("ArchiBotDocumentId")


def _upsert_document_search_attributes(
    phase: str,
    request: DocumentWorkflowRequest,
) -> None:
    if not workflow.in_workflow() or not workflow.patched("document-search-attributes-v1"):
        return
    updates = [
        ARCHIBOT_PHASE.value_set(phase),
        ARCHIBOT_PIPELINE_RUN_ID.value_set(request.pipeline_run_id),
    ]
    if request.paperless_document_id > 0:
        updates.append(ARCHIBOT_DOCUMENT_ID.value_set(request.paperless_document_id))
    workflow.upsert_search_attributes(updates)


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


def _configuration_for_phase(
    snapshot: ModelPhaseConfiguration, phase: str
) -> ModelPhaseConfiguration:
    if phase == "embedding":
        model_id = snapshot.embedding_model
        task_queue = EMBEDDING_TASK_QUEUE
    elif phase == "ocr":
        vision = snapshot.ocr_mode in {"vision_light", "vision_full"}
        model_id = snapshot.ocr_vision_model if vision else snapshot.ocr_text_model
        task_queue = OCR_VISION_TASK_QUEUE if vision else OCR_TEXT_TASK_QUEUE
    elif phase == "classification":
        model_id = snapshot.classification_model
        task_queue = CLASSIFICATION_TASK_QUEUE
    elif phase == "judge":
        model_id = snapshot.judge_model
        task_queue = JUDGE_TASK_QUEUE
    else:
        raise ValueError(f"Unknown model phase: {phase}")
    return replace(snapshot, phase=phase, model_id=model_id, task_queue=task_queue)


def _serialized_configuration_for_phase(
    snapshot: ModelPhaseConfiguration, phase: str
) -> ModelPhaseConfiguration:
    configuration = _configuration_for_phase(snapshot, phase)
    if not workflow.in_workflow() or workflow.patched("single-model-task-queue-v1"):
        return replace(configuration, task_queue=MODEL_TASK_QUEUE)
    return configuration


@workflow.defn(name=EMBEDDING_INDEX_WORKFLOW)
class EmbeddingIndexWorkflow:
    """Build one embedding generation independently from document lifecycles."""

    async def _run_generation(
        self,
        request: EmbeddingWorkflowRequest,
        configuration: ModelPhaseConfiguration,
    ) -> EmbeddingWorkflowResult:
        try:
            prepared = await workflow.execute_activity(
                prepare_embedding_generation,
                EmbeddingWorkflowRequest(request.command_id, configuration),
                schedule_to_close_timeout=timedelta(hours=24),
                start_to_close_timeout=timedelta(minutes=30),
                heartbeat_timeout=timedelta(minutes=2),
                retry_policy=RetryPolicy(
                    maximum_attempts=5,
                    maximum_interval=timedelta(minutes=1),
                    non_retryable_error_types=["ValueError"],
                ),
            )
            total = len(prepared.document_ids)
            embedded = 0
            failed = 0
            for document_id in prepared.document_ids:
                try:
                    result = await workflow.execute_activity(
                        embed_document,
                        EmbedDocumentRequest(prepared.build_id, document_id, configuration),
                        task_queue=configuration.task_queue,
                        start_to_close_timeout=timedelta(minutes=30),
                        heartbeat_timeout=timedelta(minutes=2),
                        retry_policy=RetryPolicy(maximum_attempts=5),
                    )
                    if result.status == "embedded":
                        embedded += 1
                    else:
                        total -= 1
                except ActivityError:
                    failed += 1
                progress = EmbeddingProgress(
                    prepared.command_id,
                    prepared.build_id,
                    total,
                    embedded + failed,
                    embedded,
                    failed,
                )
                await workflow.execute_activity(
                    project_embedding_progress,
                    progress,
                    start_to_close_timeout=timedelta(minutes=1),
                    retry_policy=RetryPolicy(maximum_attempts=5),
                )
            return await workflow.execute_activity(
                finish_embedding_generation,
                EmbeddingProgress(
                    prepared.command_id,
                    prepared.build_id,
                    total,
                    embedded + failed,
                    embedded,
                    failed,
                ),
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=5),
            )
        except ActivityError:
            await workflow.execute_activity(
                fail_embedding_preparation,
                EmbeddingPreparationFailure(
                    request.command_id,
                    "Temporal embedding generation exhausted its retries.",
                ),
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=5),
            )
            raise

    @workflow.run
    async def run(self, request: EmbeddingWorkflowRequest) -> EmbeddingWorkflowResult:
        snapshot = await workflow.execute_activity(
            load_model_phase_configuration,
            "embedding",
            start_to_close_timeout=timedelta(minutes=1),
            retry_policy=RetryPolicy(maximum_attempts=5),
        )
        return await self._run_generation(
            request, _serialized_configuration_for_phase(snapshot, "embedding")
        )


@workflow.defn(name=DOCUMENT_WORKFLOW)
class DocumentWorkflow:
    """Own one Paperless document identity through its review decision."""

    def __init__(self) -> None:
        self._review_decision: dict[str, Any] | None = None
        self._force_reprocess: dict[str, Any] | None = None
        self._embedding_ready = False

    @workflow.signal(name="review_decision")
    def review_decision_legacy(self, _decision: dict[str, object]) -> None:
        """Preserve replay behavior for signals dropped by the legacy decoder."""

    @workflow.signal(name="review_decision_v2")
    def review_decision(self, decision: dict[str, Any]) -> None:
        if self._review_decision is None:
            self._review_decision = decision

    @workflow.signal(name="force_reprocess")
    def force_reprocess_legacy(self, _request: dict[str, object]) -> None:
        """Preserve replay behavior for signals dropped by the legacy decoder."""

    @workflow.signal(name="force_reprocess_v2")
    def force_reprocess(self, request: dict[str, Any]) -> None:
        current_id = self._force_reprocess_pipeline_run_id(self._force_reprocess)
        replacement_id = self._force_reprocess_pipeline_run_id(request)
        if current_id is not None and replacement_id is not None and replacement_id <= current_id:
            return
        self._force_reprocess = request

    @workflow.signal(name="embedding_ready")
    def embedding_ready_legacy(self, _request: dict[str, object]) -> None:
        """Preserve replay behavior for signals dropped by the legacy decoder."""

    @workflow.signal(name="embedding_ready_v2")
    def embedding_ready(self, _request: dict[str, Any]) -> None:
        self._embedding_ready = True

    async def _project_review_completion(
        self,
        request: DocumentWorkflowRequest,
        suggestion_id: int,
        outcome: str,
    ) -> None:
        await workflow.execute_activity(
            finish_document_review,
            DocumentReviewCompletion(request.pipeline_run_id, suggestion_id, outcome),
            start_to_close_timeout=timedelta(minutes=1),
            retry_policy=RetryPolicy(maximum_attempts=5),
        )

    @staticmethod
    def _force_reprocess_pipeline_run_id(request: dict[str, Any] | None) -> int | None:
        if request is None:
            return None
        payload = request.get("payload")
        if not isinstance(payload, dict):
            return None
        pipeline_run_id = payload.get("replacement_pipeline_run_id")
        if not isinstance(pipeline_run_id, int) or isinstance(pipeline_run_id, bool):
            return None
        return pipeline_run_id

    def _replacement_request(
        self, request: DocumentWorkflowRequest
    ) -> DocumentWorkflowRequest | None:
        if self._force_reprocess is None:
            return None
        envelope = self._force_reprocess
        payload = envelope.get("payload")
        if not isinstance(payload, dict):
            raise ValueError("Force reprocess signal payload is invalid")
        replacement_id = payload.get("replacement_pipeline_run_id")
        if not isinstance(replacement_id, int) or isinstance(replacement_id, bool):
            raise ValueError("Force reprocess replacement pipeline run is invalid")
        replacement_workflow_id = payload.get("replacement_temporal_workflow_id")
        if replacement_workflow_id != request.workflow_id:
            raise ValueError("Force reprocess targets another workflow")
        self._force_reprocess = None
        if replacement_id <= request.pipeline_run_id:
            return None
        return DocumentWorkflowRequest(
            replacement_id,
            request.workflow_id,
            request.paperless_document_id,
        )

    async def _continue_if_reprocessed(self, request: DocumentWorkflowRequest) -> None:
        replacement = self._replacement_request(request)
        if replacement is None:
            return
        superseded_pipeline_run_id = request.pipeline_run_id
        while True:
            _upsert_document_search_attributes("superseded", request)
            await workflow.execute_activity(
                supersede_document_processing,
                DocumentSupersession(
                    superseded_pipeline_run_id,
                    replacement.pipeline_run_id,
                ),
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=5),
            )
            newer = self._replacement_request(replacement)
            if newer is None:
                break
            superseded_pipeline_run_id = replacement.pipeline_run_id
            replacement = newer
        workflow.continue_as_new(replacement)
        raise RuntimeError("Temporal Continue-As-New returned unexpectedly")

    async def _finish_review(
        self, request: DocumentWorkflowRequest, suggestion_id: int
    ) -> DocumentWorkflowResult:
        _upsert_document_search_attributes("awaiting_review", request)
        while self._review_decision is None:
            await workflow.wait_condition(
                lambda: self._review_decision is not None or self._force_reprocess is not None
            )
            if self._force_reprocess is not None:
                payload = self._force_reprocess.get("payload")
                if not isinstance(payload, dict):
                    raise ValueError("Force reprocess signal payload is invalid")
                if payload.get("replacement_temporal_workflow_id") != request.workflow_id:
                    if payload.get("review_suggestion_id") != suggestion_id:
                        raise ValueError("Force reprocess targets another suggestion")
                    _upsert_document_search_attributes("superseded", request)
                    await self._project_review_completion(request, suggestion_id, "superseded")
                    return DocumentWorkflowResult(
                        request.pipeline_run_id, suggestion_id, "superseded"
                    )
                await self._continue_if_reprocessed(request)
        envelope = self._review_decision or {}
        payload = envelope.get("payload")
        if not isinstance(payload, dict):
            raise ValueError("Review decision signal payload is invalid")
        if payload.get("review_suggestion_id") != suggestion_id:
            raise ValueError("Review decision targets another suggestion")
        decision = payload.get("decision")
        if decision == "rejected":
            _upsert_document_search_attributes("rejected", request)
            await self._project_review_completion(request, suggestion_id, "rejected")
            return DocumentWorkflowResult(request.pipeline_run_id, suggestion_id, "rejected")
        if decision != "accepted":
            raise ValueError("Review decision is invalid")

        commit_request = ReviewCommitRequest(
            review_suggestion_id=suggestion_id,
            command_id=int(payload["command_id"]),
            workflow_id=str(payload["temporal_workflow_id"]),
        )
        try:
            _upsert_document_search_attributes("paperless_commit", request)
            commit_result = await _execute_review_commit(commit_request)
        except ActivityError:
            await workflow.execute_activity(
                fail_review_commit,
                commit_request,
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=5),
            )
            await workflow.execute_activity(
                fail_document_processing,
                request.pipeline_run_id,
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=5),
            )
            raise
        await self._project_review_completion(request, suggestion_id, "committed")
        _upsert_document_search_attributes("completed", request)
        return DocumentWorkflowResult(request.pipeline_run_id, suggestion_id, commit_result.status)

    async def _readiness(self, request: DocumentWorkflowRequest) -> DocumentWorkflowResult | None:
        while True:
            await self._continue_if_reprocessed(request)
            readiness = await workflow.execute_activity(
                check_document_readiness,
                request.pipeline_run_id,
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=5),
            )
            await self._continue_if_reprocessed(request)
            if readiness.status == "complete":
                if readiness.review_suggestion_id is None:
                    raise RuntimeError("Ready document workflow has no review suggestion")
                return await self._finish_review(request, readiness.review_suggestion_id)
            if readiness.status == "finished":
                _upsert_document_search_attributes("completed", request)
                return DocumentWorkflowResult(
                    request.pipeline_run_id,
                    readiness.review_suggestion_id,
                    "completed",
                )
            if readiness.status == "cancelled":
                _upsert_document_search_attributes("cancelled", request)
                return DocumentWorkflowResult(request.pipeline_run_id, None, "cancelled")
            if readiness.status == "ready":
                return None
            _upsert_document_search_attributes("waiting_for_embedding", request)
            await workflow.wait_condition(
                lambda: self._embedding_ready or self._force_reprocess is not None
            )
            await self._continue_if_reprocessed(request)
            self._embedding_ready = False

    async def _run_owned_lifecycle(
        self, request: DocumentWorkflowRequest
    ) -> DocumentWorkflowResult:
        ready_result = await self._readiness(request)
        if ready_result is not None:
            return ready_result

        snapshot = await workflow.execute_activity(
            load_model_phase_configuration,
            "embedding",
            start_to_close_timeout=timedelta(minutes=1),
            retry_policy=RetryPolicy(maximum_attempts=5),
        )
        phase_activities = {
            "ocr": process_document_ocr_phase,
            "embedding": process_document_embedding_phase,
            "classification": process_document_classification_phase,
            "judge": process_document_judge_phase,
        }
        cycle = request.pipeline_run_id
        for phase in ("ocr", "embedding", "classification", "judge"):
            await self._continue_if_reprocessed(request)
            _upsert_document_search_attributes(phase, request)
            configuration = _serialized_configuration_for_phase(snapshot, phase)
            try:
                result = await workflow.execute_activity(
                    phase_activities[phase],
                    DocumentPhaseRequest(request.pipeline_run_id, cycle, configuration),
                    task_queue=configuration.task_queue,
                    schedule_to_close_timeout=timedelta(hours=24),
                    start_to_close_timeout=timedelta(hours=2),
                    heartbeat_timeout=timedelta(minutes=2),
                    retry_policy=RetryPolicy(
                        maximum_attempts=5,
                        maximum_interval=timedelta(minutes=2),
                        non_retryable_error_types=["ValueError"],
                    ),
                )
            except ActivityError:
                await workflow.execute_activity(
                    fail_document_processing,
                    request.pipeline_run_id,
                    start_to_close_timeout=timedelta(minutes=1),
                    retry_policy=RetryPolicy(maximum_attempts=5),
                )
                raise
            if result.status == "failed_permanent":
                await workflow.execute_activity(
                    fail_document_processing,
                    request.pipeline_run_id,
                    start_to_close_timeout=timedelta(minutes=1),
                    retry_policy=RetryPolicy(maximum_attempts=5),
                )
                raise RuntimeError(f"Temporal document {phase} phase failed permanently")
            await self._continue_if_reprocessed(request)

        await self._continue_if_reprocessed(request)
        _upsert_document_search_attributes("publishing_review", request)
        review_configuration = _serialized_configuration_for_phase(snapshot, "judge")
        try:
            result = await workflow.execute_activity(
                publish_document_review,
                DocumentPhaseRequest(request.pipeline_run_id, cycle, review_configuration),
                task_queue=PAPERLESS_TASK_QUEUE,
                schedule_to_close_timeout=timedelta(hours=24),
                start_to_close_timeout=timedelta(minutes=10),
                heartbeat_timeout=timedelta(minutes=2),
                retry_policy=RetryPolicy(maximum_attempts=5),
            )
        except ActivityError:
            await workflow.execute_activity(
                fail_document_processing,
                request.pipeline_run_id,
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=5),
            )
            raise
        return await self._finish_review(request, result.review_suggestion_id)

    @workflow.run
    async def run(self, request: DocumentWorkflowRequest) -> DocumentWorkflowResult:
        _upsert_document_search_attributes("starting", request)
        await self._continue_if_reprocessed(request)
        return await self._run_owned_lifecycle(request)


async def _execute_review_commit(request: ReviewCommitRequest) -> ReviewCommitResult:
    return await workflow.execute_activity(
        commit_review_suggestion,
        request,
        task_queue=PAPERLESS_TASK_QUEUE,
        schedule_to_close_timeout=timedelta(hours=24),
        start_to_close_timeout=timedelta(minutes=10),
        heartbeat_timeout=timedelta(minutes=2),
        retry_policy=RetryPolicy(
            maximum_attempts=5,
            non_retryable_error_types=["ValueError"],
        ),
    )


@workflow.defn(name=REVIEW_COMMIT_WORKFLOW)
class ReviewCommitWorkflow:
    """Commit an accepted suggestion that predates its document workflow."""

    @workflow.run
    async def run(self, request: ReviewCommitRequest) -> ReviewCommitResult:
        try:
            return await _execute_review_commit(request)
        except ActivityError:
            await workflow.execute_activity(
                fail_review_commit,
                request,
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=5),
            )
            raise


@workflow.defn(name=POLL_RECONCILIATION_WORKFLOW)
class PollReconciliationWorkflow:
    """Discover inbox identities and detach their independent document workflows."""

    @workflow.run
    async def run(self, request: PollWorkflowRequest) -> PollWorkflowResult:
        return await _run_poll_reconciliation(request)


async def _run_poll_reconciliation(request: PollWorkflowRequest) -> PollWorkflowResult:
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
            retry_policy=RetryPolicy(maximum_attempts=5),
        )
        raise

    started = 0
    for child in discovery.workflow_starts:
        child_request = DocumentWorkflowRequest(
            child.pipeline_run_id,
            child.workflow_id,
            child.paperless_document_id,
        )
        try:
            await workflow.start_child_workflow(
                DocumentWorkflow.run,
                child_request,
                id=child.workflow_id,
                parent_close_policy=workflow.ParentClosePolicy.ABANDON,
                id_reuse_policy=(
                    WorkflowIDReusePolicy.ALLOW_DUPLICATE
                    if child.force
                    else WorkflowIDReusePolicy.REJECT_DUPLICATE
                ),
            )
        except WorkflowAlreadyStartedError:
            if child.force:
                handle = workflow.get_external_workflow_handle(child.workflow_id)
                await handle.signal(
                    "force_reprocess_v2",
                    {
                        "intent_id": (f"poll-force:{request.command_id}:{child.pipeline_run_id}"),
                        "payload": {
                            "replacement_pipeline_run_id": child.pipeline_run_id,
                            "replacement_temporal_workflow_id": child.workflow_id,
                        },
                    },
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
        retry_policy=RetryPolicy(maximum_attempts=5),
    )


@workflow.defn(name=SCHEDULED_POLL_RECONCILIATION_WORKFLOW)
class ScheduledPollReconciliationWorkflow:
    """Create one auditable command and execute a scheduled reconciliation."""

    @workflow.run
    async def run(self) -> PollWorkflowResult | None:
        info = workflow.info()
        request = await workflow.execute_activity(
            create_scheduled_poll_command,
            ScheduledPollStart(info.workflow_id, info.run_id),
            start_to_close_timeout=timedelta(minutes=1),
            retry_policy=RetryPolicy(maximum_attempts=5),
        )
        if request is None:
            return None
        return await _run_poll_reconciliation(request)
