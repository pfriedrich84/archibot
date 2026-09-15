"""Deterministic Temporal workflows."""

from __future__ import annotations

import asyncio
from contextlib import suppress
from dataclasses import dataclass, replace
from datetime import timedelta

from temporalio import workflow
from temporalio.common import RetryPolicy, WorkflowIDReusePolicy
from temporalio.exceptions import ActivityError, WorkflowAlreadyStartedError

with workflow.unsafe.imports_passed_through():
    from app.temporal.contracts import (
        DocumentPhaseNotification,
        DocumentPhaseRegistration,
        DocumentPhaseRequest,
        DocumentPhaseResult,
        DocumentWorkflowRequest,
        DocumentWorkflowResult,
        EmbeddingIndexPhaseRegistration,
        EmbeddingIndexPhaseResult,
        EmbeddingPreparationFailure,
        EmbeddingProgress,
        EmbeddingWorkflowRequest,
        EmbeddingWorkflowResult,
        EmbedDocumentRequest,
        LegacyDocumentWorkflowRequest,
        LegacyEmbeddingWorkflowRequest,
        LegacyEmbedDocumentRequest,
        ModelPhaseConfiguration,
        ModelPhaseGrant,
        ModelPhaseProjection,
        ModelPhaseSchedulerRequest,
        OcrPhaseSelectionRequest,
        PollWorkflowRequest,
        PollWorkflowResult,
        ReviewCommitRequest,
        ReviewCommitResult,
        ReviewRelease,
    )
    from app.temporal.document_activities import (
        check_document_readiness,
        discover_inbox_documents,
        fail_document_processing,
        fail_poll_discovery,
        finish_poll_discovery,
        process_document_for_review,
    )
    from app.temporal.document_phase_activities import (
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
    from app.temporal.phase_activities import (
        embedding_index_status,
        load_model_phase_configuration,
        project_model_phase,
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
    MODEL_PHASE_SCHEDULER_WORKFLOW,
    MODEL_PHASE_SCHEDULER_WORKFLOW_ID,
    OCR_TEXT_TASK_QUEUE,
    OCR_VISION_TASK_QUEUE,
    PAPERLESS_TASK_QUEUE,
    POLL_RECONCILIATION_WORKFLOW,
    REVIEW_COMMIT_WORKFLOW,
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


@workflow.defn(name=MODEL_PHASE_SCHEDULER_WORKFLOW)
class ModelPhaseSchedulerWorkflow:
    """Drain document work in model-affine phases without switching backwards."""

    def __init__(self) -> None:
        self._cycle = 0
        self._phase = "idle"
        self._pending_documents: dict[str, DocumentPhaseRegistration] = {}
        self._active_documents: dict[str, DocumentPhaseRegistration] = {}
        self._seen_document_intents: set[str] = set()
        self._pending_indexes: dict[str, EmbeddingIndexPhaseRegistration] = {}
        self._active_indexes: dict[str, EmbeddingIndexPhaseRegistration] = {}
        self._seen_index_intents: set[str] = set()
        self._index_results: dict[str, EmbeddingIndexPhaseResult] = {}

    @workflow.signal(name="register_document")
    def register_document(self, registration: DocumentPhaseRegistration) -> None:
        if registration.intent_id in self._seen_document_intents:
            return
        self._seen_document_intents.add(registration.intent_id)
        target = self._active_documents if self._phase == "embedding" else self._pending_documents
        target.setdefault(registration.workflow_id, registration)

    @workflow.signal(name="register_embedding_index")
    def register_embedding_index(self, registration: EmbeddingIndexPhaseRegistration) -> None:
        if registration.intent_id in self._seen_index_intents:
            return
        self._seen_index_intents.add(registration.intent_id)
        target = self._active_indexes if self._phase == "embedding" else self._pending_indexes
        target.setdefault(registration.workflow_id, registration)

    @workflow.signal(name="embedding_index_completed")
    def embedding_index_completed(self, result: EmbeddingIndexPhaseResult) -> None:
        self._index_results[result.intent_id] = result

    async def _configuration(self, phase: str) -> ModelPhaseConfiguration:
        return await workflow.execute_activity(
            load_model_phase_configuration,
            phase,
            start_to_close_timeout=timedelta(minutes=1),
            retry_policy=RetryPolicy(maximum_attempts=5),
        )

    @staticmethod
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

    async def _project(
        self,
        configuration: ModelPhaseConfiguration,
        status: str,
        total: int,
        done: int,
        failed: int,
    ) -> None:
        await workflow.execute_activity(
            project_model_phase,
            ModelPhaseProjection(
                scheduler_workflow_id=MODEL_PHASE_SCHEDULER_WORKFLOW_ID,
                cycle=self._cycle,
                phase=configuration.phase,
                status=status,
                model_id=configuration.model_id,
                configuration_revision=configuration.configuration_revision,
                total=total,
                done=done,
                failed=failed,
            ),
            start_to_close_timeout=timedelta(minutes=1),
            retry_policy=RetryPolicy(maximum_attempts=5),
        )

    async def _notify_document(
        self, registration: DocumentPhaseRegistration, result: DocumentPhaseResult
    ) -> None:
        handle = workflow.get_external_workflow_handle(registration.workflow_id)
        await handle.signal(
            "model_phase_result", DocumentPhaseNotification(cycle=self._cycle, result=result)
        )

    async def _execute_document_phase(
        self,
        phase: str,
        configuration: ModelPhaseConfiguration,
        registrations: list[DocumentPhaseRegistration],
    ) -> set[str]:
        activities = {
            "embedding": (process_document_embedding_phase, EMBEDDING_TASK_QUEUE),
            "ocr": (
                process_document_ocr_phase,
                OCR_VISION_TASK_QUEUE
                if configuration.ocr_mode in {"vision_light", "vision_full"}
                else OCR_TEXT_TASK_QUEUE,
            ),
            "classification": (process_document_classification_phase, CLASSIFICATION_TASK_QUEUE),
            "judge": (process_document_judge_phase, JUDGE_TASK_QUEUE),
        }
        phase_activity, task_queue = activities[phase]
        requests = [
            DocumentPhaseRequest(reg.pipeline_run_id, self._cycle, configuration)
            for reg in registrations
        ]
        outcomes = await asyncio.gather(
            *[
                workflow.execute_activity(
                    phase_activity,
                    request,
                    task_queue=task_queue,
                    schedule_to_close_timeout=timedelta(hours=24),
                    start_to_close_timeout=timedelta(hours=2),
                    heartbeat_timeout=timedelta(minutes=2),
                    retry_policy=RetryPolicy(
                        maximum_attempts=5,
                        maximum_interval=timedelta(minutes=2),
                        non_retryable_error_types=["ValueError"],
                    ),
                )
                for request in requests
            ],
            return_exceptions=True,
        )
        failures: set[str] = set()
        for registration, outcome in zip(registrations, outcomes, strict=True):
            if isinstance(outcome, BaseException):
                failures.add(registration.workflow_id)
                result = DocumentPhaseResult(
                    registration.pipeline_run_id,
                    phase,
                    "failed_permanent",
                    "Temporal activity retries exhausted.",
                )
            else:
                result = outcome
            await self._notify_document(registration, result)
        return failures

    async def _run_embedding_phase(self) -> tuple[ModelPhaseConfiguration, set[str]]:
        self._phase = "embedding"
        configuration = self._cycle_configuration
        processed: set[str] = set()
        granted_indexes: set[str] = set()
        failures: set[str] = set()
        await self._project(configuration, "running", len(self._active_documents), 0, 0)

        while True:
            registrations = [
                registration
                for workflow_id, registration in self._active_documents.items()
                if workflow_id not in processed
            ]
            if registrations:
                phase_failures = await self._execute_document_phase(
                    "embedding", configuration, registrations
                )
                processed.update(reg.workflow_id for reg in registrations)
                failures.update(phase_failures)

            for workflow_id in self._active_indexes:
                if workflow_id in granted_indexes:
                    continue
                handle = workflow.get_external_workflow_handle(workflow_id)
                await handle.signal(
                    "model_phase_grant", ModelPhaseGrant(self._cycle, configuration)
                )
                granted_indexes.add(workflow_id)

            await self._project(
                configuration,
                "running",
                len(self._active_documents),
                len(processed),
                len(failures),
            )
            index_failed = any(
                self._index_results.get(
                    reg.intent_id, EmbeddingIndexPhaseResult("", "", 0, "")
                ).status
                == "failed"
                for reg in self._active_indexes.values()
            )
            if index_failed:
                failures.update(self._active_documents)
                for registration in self._active_documents.values():
                    await self._notify_document(
                        registration,
                        DocumentPhaseResult(
                            registration.pipeline_run_id,
                            "embedding",
                            "failed_permanent",
                            "Embedding index generation failed.",
                        ),
                    )
                break

            index_status = await workflow.execute_activity(
                embedding_index_status,
                configuration.embedding_model,
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=5),
            )
            if index_status == "failed":
                failures.update(self._active_documents)
                for registration in self._active_documents.values():
                    await self._notify_document(
                        registration,
                        DocumentPhaseResult(
                            registration.pipeline_run_id,
                            "embedding",
                            "failed_permanent",
                            "Embedding index is failed.",
                        ),
                    )
                break
            # Signals are applied at workflow-task boundaries, including while the
            # readiness activity is in flight. Re-evaluate every active collection
            # after that await so late embedding registrations cannot cross the
            # boundary without being granted and completed.
            documents_done = all(workflow_id in processed for workflow_id in self._active_documents)
            indexes_granted = all(
                workflow_id in granted_indexes for workflow_id in self._active_indexes
            )
            indexes_done = all(
                registration.intent_id in self._index_results
                for registration in self._active_indexes.values()
            )
            if documents_done and indexes_granted and indexes_done and index_status == "complete":
                break
            await workflow.sleep(timedelta(seconds=5))
        await self._project(
            configuration,
            "completed",
            len(self._active_documents),
            len(processed),
            len(failures),
        )
        return configuration, failures

    async def _run_fixed_phase(
        self,
        phase: str,
        excluded: set[str],
    ) -> tuple[ModelPhaseConfiguration, set[str]]:
        self._phase = phase
        configuration = self._configuration_for_phase(self._cycle_configuration, phase)
        registrations = [
            registration
            for workflow_id, registration in self._active_documents.items()
            if workflow_id not in excluded
        ]
        if phase == "ocr":
            if configuration.ocr_mode == "off":
                registrations = []
            elif configuration.ocr_requested_tag_id > 0:
                registrations = await workflow.execute_activity(
                    select_document_ocr_phase,
                    OcrPhaseSelectionRequest(
                        registrations=registrations,
                        requested_tag_id=configuration.ocr_requested_tag_id,
                    ),
                    task_queue=PAPERLESS_TASK_QUEUE,
                    schedule_to_close_timeout=timedelta(hours=1),
                    start_to_close_timeout=timedelta(minutes=10),
                    heartbeat_timeout=timedelta(minutes=2),
                    retry_policy=RetryPolicy(maximum_attempts=5),
                )
        await self._project(configuration, "running", len(registrations), 0, 0)
        failures = (
            await self._execute_document_phase(phase, configuration, registrations)
            if registrations
            else set()
        )
        await self._project(
            configuration,
            "completed",
            len(registrations),
            len(registrations),
            len(failures),
        )
        return configuration, failures

    @workflow.run
    async def run(self, request: ModelPhaseSchedulerRequest) -> None:
        if request.scheduler_id != MODEL_PHASE_SCHEDULER_WORKFLOW_ID:
            raise ValueError("Model phase scheduler identity is invalid")
        self._cycle = request.initial_cycle
        self._pending_documents = {
            registration.workflow_id: registration for registration in request.pending_documents
        }
        self._pending_indexes = {
            registration.workflow_id: registration for registration in request.pending_indexes
        }
        self._seen_document_intents = set(request.seen_document_intents)
        self._seen_document_intents.update(
            registration.intent_id for registration in request.pending_documents
        )
        self._seen_index_intents = set(request.seen_index_intents)
        self._seen_index_intents.update(
            registration.intent_id for registration in request.pending_indexes
        )
        while True:
            self._phase = "idle"
            await workflow.wait_condition(
                lambda: bool(self._pending_documents or self._pending_indexes)
            )
            self._cycle += 1
            self._active_documents = self._pending_documents
            self._pending_documents = {}
            self._active_indexes = self._pending_indexes
            self._pending_indexes = {}
            self._index_results = {}
            self._cycle_configuration = self._configuration_for_phase(
                await self._configuration("embedding"), "embedding"
            )

            _, failed = await self._run_embedding_phase()
            review_configuration: ModelPhaseConfiguration | None = None
            for phase in ("ocr", "classification", "judge"):
                review_configuration, phase_failures = await self._run_fixed_phase(phase, failed)
                failed.update(phase_failures)

            self._phase = "review_release"
            if review_configuration is None:
                raise RuntimeError("Model phase configuration is unavailable")
            for workflow_id, registration in self._active_documents.items():
                if workflow_id in failed:
                    continue
                handle = workflow.get_external_workflow_handle(registration.workflow_id)
                await handle.signal(
                    "review_release", ReviewRelease(self._cycle, review_configuration)
                )
            self._active_documents = {}
            self._active_indexes = {}
            if self._cycle % 10 == 0:
                workflow.continue_as_new(
                    ModelPhaseSchedulerRequest(
                        scheduler_id=MODEL_PHASE_SCHEDULER_WORKFLOW_ID,
                        initial_cycle=self._cycle,
                        pending_documents=list(self._pending_documents.values()),
                        pending_indexes=list(self._pending_indexes.values()),
                        seen_document_intents=sorted(self._seen_document_intents),
                        seen_index_intents=sorted(self._seen_index_intents),
                    )
                )


async def _ensure_scheduler() -> None:
    with suppress(WorkflowAlreadyStartedError):
        await workflow.start_child_workflow(
            ModelPhaseSchedulerWorkflow.run,
            ModelPhaseSchedulerRequest(MODEL_PHASE_SCHEDULER_WORKFLOW_ID),
            id=MODEL_PHASE_SCHEDULER_WORKFLOW_ID,
            parent_close_policy=workflow.ParentClosePolicy.ABANDON,
            id_reuse_policy=WorkflowIDReusePolicy.REJECT_DUPLICATE,
        )


@workflow.defn(name=EMBEDDING_INDEX_WORKFLOW)
class EmbeddingIndexWorkflow:
    """Build one embedding generation only while the embedding phase is active."""

    def __init__(self) -> None:
        self._grant: ModelPhaseGrant | None = None

    @workflow.signal(name="model_phase_grant")
    def model_phase_grant(self, grant: ModelPhaseGrant) -> None:
        if self._grant is None:
            self._grant = grant

    async def _run_legacy(self, request: EmbeddingWorkflowRequest) -> EmbeddingWorkflowResult:
        try:
            prepared = await workflow.execute_activity(
                prepare_embedding_generation,
                LegacyEmbeddingWorkflowRequest(request.command_id),
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
                    request.command_id,
                    "Temporal embedding preparation exhausted its retries.",
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
                    LegacyEmbedDocumentRequest(prepared.build_id, document_id),
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
                request.command_id,
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
                retry_policy=RetryPolicy(maximum_attempts=0),
            )
        return await workflow.execute_activity(
            finish_embedding_generation,
            EmbeddingProgress(
                request.command_id,
                prepared.build_id,
                total,
                embedded + failed,
                embedded,
                failed,
            ),
            start_to_close_timeout=timedelta(minutes=1),
            retry_policy=RetryPolicy(maximum_attempts=0),
        )

    @workflow.run
    async def run(self, request: EmbeddingWorkflowRequest) -> EmbeddingWorkflowResult:
        if not workflow.patched("model-phase-embedding-workflow-v1"):
            return await self._run_legacy(request)
        await _ensure_scheduler()
        workflow_id = workflow.info().workflow_id
        registration = EmbeddingIndexPhaseRegistration(
            intent_id=f"{workflow_id}:embedding-index",
            workflow_id=workflow_id,
            command_id=request.command_id,
        )
        scheduler = workflow.get_external_workflow_handle(MODEL_PHASE_SCHEDULER_WORKFLOW_ID)
        await scheduler.signal("register_embedding_index", registration)
        await workflow.wait_condition(lambda: self._grant is not None)
        grant = self._grant
        if grant is None:
            raise RuntimeError("Embedding phase grant is unavailable")
        terminal_status = "failed"
        try:
            prepared = await workflow.execute_activity(
                prepare_embedding_generation,
                EmbeddingWorkflowRequest(request.command_id, grant.configuration),
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
                        EmbedDocumentRequest(prepared.build_id, document_id, grant.configuration),
                        task_queue=EMBEDDING_TASK_QUEUE,
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
            result = await workflow.execute_activity(
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
            terminal_status = "complete" if result.status == "complete" else "failed"
            return result
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
        finally:
            await scheduler.signal(
                "embedding_index_completed",
                EmbeddingIndexPhaseResult(
                    registration.intent_id,
                    workflow_id,
                    request.command_id,
                    terminal_status,
                ),
            )


@workflow.defn(name=DOCUMENT_WORKFLOW)
class DocumentWorkflow:
    """Own one Paperless document identity through its review decision."""

    def __init__(self) -> None:
        self._review_decision: dict[str, object] | None = None
        self._review_release: ReviewRelease | None = None
        self._phase_failure: DocumentPhaseResult | None = None

    @workflow.signal(name="model_phase_result")
    def model_phase_result(self, notification: DocumentPhaseNotification) -> None:
        if notification.result.status == "failed_permanent" and self._phase_failure is None:
            self._phase_failure = notification.result

    @workflow.signal(name="review_release")
    def review_release(self, release: ReviewRelease) -> None:
        if self._review_release is None:
            self._review_release = release

    @workflow.signal(name="review_decision")
    def review_decision(self, decision: dict[str, object]) -> None:
        if self._review_decision is None:
            self._review_decision = decision

    async def _finish_review(
        self, request: DocumentWorkflowRequest, suggestion_id: int
    ) -> DocumentWorkflowResult:
        await workflow.wait_condition(lambda: self._review_decision is not None)
        envelope = self._review_decision or {}
        payload = envelope.get("payload")
        if not isinstance(payload, dict):
            raise ValueError("Review decision signal payload is invalid")
        if payload.get("review_suggestion_id") != suggestion_id:
            raise ValueError("Review decision targets another suggestion")
        decision = payload.get("decision")
        if decision == "rejected":
            return DocumentWorkflowResult(request.pipeline_run_id, suggestion_id, "rejected")
        if decision != "accepted":
            raise ValueError("Review decision is invalid")

        commit_request = ReviewCommitRequest(
            review_suggestion_id=suggestion_id,
            command_id=int(payload["command_id"]),
            workflow_id=str(payload["temporal_workflow_id"]),
        )
        try:
            commit_result = await _execute_review_commit(commit_request)
        except ActivityError:
            await workflow.execute_activity(
                fail_review_commit,
                commit_request,
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=5),
            )
            raise
        return DocumentWorkflowResult(request.pipeline_run_id, suggestion_id, commit_result.status)

    async def _run_legacy(self, request: DocumentWorkflowRequest) -> DocumentWorkflowResult:
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
        if suggestion_id is None:
            raise RuntimeError("Legacy document workflow has no review suggestion")
        return await self._finish_review(request, suggestion_id)

    @workflow.run
    async def run(self, request: DocumentWorkflowRequest) -> DocumentWorkflowResult:
        if not workflow.patched("model-phase-document-workflow-v1"):
            return await self._run_legacy(request)
        await _ensure_scheduler()
        workflow_id = request.workflow_id or workflow.info().workflow_id
        scheduler = workflow.get_external_workflow_handle(MODEL_PHASE_SCHEDULER_WORKFLOW_ID)
        await scheduler.signal(
            "register_document",
            DocumentPhaseRegistration(
                intent_id=f"{workflow_id}:document",
                workflow_id=workflow_id,
                pipeline_run_id=request.pipeline_run_id,
            ),
        )
        await workflow.wait_condition(
            lambda: self._review_release is not None or self._phase_failure is not None
        )
        if self._phase_failure is not None:
            await workflow.execute_activity(
                fail_document_processing,
                request.pipeline_run_id,
                start_to_close_timeout=timedelta(minutes=1),
                retry_policy=RetryPolicy(maximum_attempts=5),
            )
            return DocumentWorkflowResult(request.pipeline_run_id, None, "failed_permanent")
        release = self._review_release
        if release is None:
            raise RuntimeError("Review release is unavailable")
        try:
            result = await workflow.execute_activity(
                publish_document_review,
                DocumentPhaseRequest(request.pipeline_run_id, release.cycle, release.configuration),
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
        suggestion_id = result.review_suggestion_id

        return await self._finish_review(request, suggestion_id)


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
        stable_document_identity = workflow.patched("stable-document-identity-v1")
        for child in discovery.workflow_starts:
            with suppress(WorkflowAlreadyStartedError):
                await workflow.start_child_workflow(
                    DocumentWorkflow.run,
                    (
                        DocumentWorkflowRequest(child.pipeline_run_id, child.workflow_id)
                        if stable_document_identity
                        else LegacyDocumentWorkflowRequest(child.pipeline_run_id)
                    ),
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
            retry_policy=RetryPolicy(maximum_attempts=5),
        )
