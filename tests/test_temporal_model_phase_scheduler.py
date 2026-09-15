from __future__ import annotations

from dataclasses import replace
from types import SimpleNamespace

import pytest
from temporalio import workflow
from temporalio.worker.workflow_sandbox import SandboxedWorkflowRunner

from app.temporal import document_phase_activities, phase_activities, workflows
from app.temporal.contracts import (
    DocumentPhaseRegistration,
    DocumentWorkflowStart,
    EmbeddingIndexPhaseResult,
    EmbeddingProgress,
    EmbeddingWorkflowRequest,
    EmbeddingWorkflowResult,
    LegacyDocumentWorkflowRequest,
    LegacyEmbeddingWorkflowRequest,
    ModelPhaseConfiguration,
    ModelPhaseGrant,
    ModelPhaseSchedulerRequest,
    OcrPhaseSelectionRequest,
    PollDiscoveryResult,
    PollWorkflowRequest,
    PollWorkflowResult,
    PreparedEmbeddingBuild,
)
from app.temporal.names import MODEL_PHASE_SCHEDULER_WORKFLOW_ID


def _configuration(phase: str) -> ModelPhaseConfiguration:
    return ModelPhaseConfiguration(
        phase=phase,
        provider_type="ollama",
        provider_base_url="http://provider",
        model_id=f"{phase}-model",
        configuration_revision=f"{phase}-revision",
        task_queue=f"archibot-model-{phase}",
        embedding_model="embed",
        classification_model="classify",
        ocr_text_model="ocr-text",
        ocr_vision_model="ocr-vision",
        judge_model="judge",
        ocr_mode="off",
        judge_enabled=True,
        judge_confidence_threshold=50,
        embedding_num_ctx=2048,
        classification_num_ctx=8192,
        ocr_num_ctx=16384,
    )


def test_documents_arriving_after_embedding_boundary_wait_for_next_cycle():
    scheduler = workflows.ModelPhaseSchedulerWorkflow()
    first = DocumentPhaseRegistration("first", "archibot/document/1", 11)
    late = DocumentPhaseRegistration("late", "archibot/document/2", 12)

    scheduler._phase = "embedding"
    scheduler.register_document(first)
    scheduler._phase = "classification"
    scheduler.register_document(late)
    scheduler.register_document(late)

    assert scheduler._active_documents == {first.workflow_id: first}
    assert scheduler._pending_documents == {late.workflow_id: late}


def test_phase_configuration_pins_models_and_context_windows(monkeypatch):
    monkeypatch.setattr(phase_activities.settings, "ollama_embed_num_ctx", 2048)
    monkeypatch.setattr(phase_activities.settings, "ollama_num_ctx", 8192)
    monkeypatch.setattr(phase_activities.settings, "ollama_ocr_num_ctx", 16384)

    configuration = phase_activities._load_model_phase_configuration("classification")

    assert configuration.embedding_num_ctx == 2048
    assert configuration.classification_num_ctx == 8192
    assert configuration.ocr_num_ctx == 16384


def test_cycle_configuration_selects_each_role_without_changing_snapshot():
    snapshot = _configuration("embedding")

    classification = workflows.ModelPhaseSchedulerWorkflow._configuration_for_phase(
        snapshot, "classification"
    )
    judge = workflows.ModelPhaseSchedulerWorkflow._configuration_for_phase(snapshot, "judge")

    assert classification.model_id == "classify"
    assert classification.task_queue == "archibot-model-classification"
    assert judge.model_id == "judge"
    assert judge.task_queue == "archibot-model-judge"
    assert classification.configuration_revision == snapshot.configuration_revision
    assert judge.configuration_revision == snapshot.configuration_revision


@pytest.mark.asyncio
async def test_all_product_workflows_prepare_in_temporal_sandbox():
    runner = SandboxedWorkflowRunner()
    for workflow_class in (
        workflows.ModelPhaseSchedulerWorkflow,
        workflows.EmbeddingIndexWorkflow,
        workflows.DocumentWorkflow,
        workflows.ReviewCommitWorkflow,
        workflows.PollReconciliationWorkflow,
    ):
        runner.prepare_workflow(workflow._Definition.must_from_class(workflow_class))


@pytest.mark.asyncio
async def test_scheduler_runs_model_phases_in_order_before_review_release(monkeypatch):
    class StopScheduler(Exception):
        pass

    events: list[str] = []
    released = []
    scheduler = workflows.ModelPhaseSchedulerWorkflow()
    registration = DocumentPhaseRegistration("first", "archibot/document/1", 11)

    async def wait_condition(predicate):
        if predicate():
            return
        raise StopScheduler

    async def embedding_phase():
        events.append("embedding")
        return _configuration("embedding"), set()

    async def fixed_phase(phase, _excluded):
        events.append(phase)
        return _configuration(phase), set()

    class Handle:
        async def signal(self, name, payload):
            released.append((name, payload))

    monkeypatch.setattr(workflows.workflow, "wait_condition", wait_condition)
    monkeypatch.setattr(scheduler, "_configuration", lambda _phase: _async_configuration())
    monkeypatch.setattr(workflows.workflow, "get_external_workflow_handle", lambda _id: Handle())
    monkeypatch.setattr(scheduler, "_run_embedding_phase", embedding_phase)
    monkeypatch.setattr(scheduler, "_run_fixed_phase", fixed_phase)

    with pytest.raises(StopScheduler):
        await scheduler.run(
            ModelPhaseSchedulerRequest(
                MODEL_PHASE_SCHEDULER_WORKFLOW_ID,
                pending_documents=[registration],
            )
        )

    assert events == ["embedding", "ocr", "classification", "judge"]
    assert released[0][0] == "review_release"
    assert released[0][1].cycle == 1
    assert scheduler._seen_document_intents == {registration.intent_id}


@pytest.mark.asyncio
async def test_scheduler_restores_signal_deduplication_across_continue_as_new(monkeypatch):
    class StopScheduler(Exception):
        pass

    registration = DocumentPhaseRegistration("already-seen", "archibot/document/1", 11)
    scheduler = workflows.ModelPhaseSchedulerWorkflow()

    async def wait_condition(predicate):
        scheduler.register_document(registration)
        assert not predicate()
        raise StopScheduler

    monkeypatch.setattr(workflows.workflow, "wait_condition", wait_condition)

    with pytest.raises(StopScheduler):
        await scheduler.run(
            ModelPhaseSchedulerRequest(
                MODEL_PHASE_SCHEDULER_WORKFLOW_ID,
                seen_document_intents=[registration.intent_id],
            )
        )

    assert scheduler._pending_documents == {}
    assert scheduler._active_documents == {}


@pytest.mark.asyncio
async def test_embedding_phase_drains_registration_received_during_readiness_check(monkeypatch):
    scheduler = workflows.ModelPhaseSchedulerWorkflow()
    first = DocumentPhaseRegistration("first", "archibot/document/1", 11)
    late = DocumentPhaseRegistration("late", "archibot/document/2", 12)
    scheduler._cycle = 1
    scheduler._cycle_configuration = _configuration("embedding")
    scheduler._active_documents = {first.workflow_id: first}
    scheduler._active_indexes = {}
    processed_batches: list[list[str]] = []
    readiness_checks = 0

    async def project(*_args):
        return None

    async def execute_phase(_phase, _configuration, registrations):
        processed_batches.append([registration.workflow_id for registration in registrations])
        return set()

    async def execute_activity(activity_fn, _argument, **_kwargs):
        nonlocal readiness_checks
        assert activity_fn is workflows.embedding_index_status
        readiness_checks += 1
        if readiness_checks == 1:
            scheduler.register_document(late)
        return "complete"

    async def sleep(_duration):
        return None

    monkeypatch.setattr(scheduler, "_project", project)
    monkeypatch.setattr(scheduler, "_execute_document_phase", execute_phase)
    monkeypatch.setattr(workflows.workflow, "execute_activity", execute_activity)
    monkeypatch.setattr(workflows.workflow, "sleep", sleep)

    _, failures = await scheduler._run_embedding_phase()

    assert failures == set()
    assert processed_batches == [[first.workflow_id], [late.workflow_id]]
    assert readiness_checks == 2


@pytest.mark.asyncio
async def test_ocr_phase_dispatches_no_tasks_when_mode_is_off(monkeypatch):
    scheduler = workflows.ModelPhaseSchedulerWorkflow()
    registration = DocumentPhaseRegistration("first", "archibot/document/1", 11)
    scheduler._cycle = 1
    scheduler._cycle_configuration = _configuration("embedding")
    scheduler._active_documents = {registration.workflow_id: registration}
    projections = []

    async def project(_configuration, status, total, done, failed):
        projections.append((status, total, done, failed))

    async def execute_phase(*_args):
        pytest.fail("OCR activity must not be dispatched while OCR_MODE is off")

    monkeypatch.setattr(scheduler, "_project", project)
    monkeypatch.setattr(scheduler, "_execute_document_phase", execute_phase)

    _, failures = await scheduler._run_fixed_phase("ocr", set())

    assert failures == set()
    assert projections == [("running", 0, 0, 0), ("completed", 0, 0, 0)]


@pytest.mark.asyncio
async def test_ocr_phase_dispatches_all_documents_without_tag_filter(monkeypatch):
    scheduler = workflows.ModelPhaseSchedulerWorkflow()
    registration = DocumentPhaseRegistration("first", "archibot/document/1", 11)
    scheduler._cycle = 1
    scheduler._cycle_configuration = replace(
        _configuration("embedding"), ocr_mode="text", ocr_requested_tag_id=0
    )
    scheduler._active_documents = {registration.workflow_id: registration}
    dispatched = []

    async def project(*_args):
        return None

    async def execute_phase(_phase, _configuration, registrations):
        dispatched.extend(registrations)
        return set()

    async def execute_activity(*_args, **_kwargs):
        pytest.fail("Paperless tag selection must not run without an OCR tag filter")

    monkeypatch.setattr(scheduler, "_project", project)
    monkeypatch.setattr(scheduler, "_execute_document_phase", execute_phase)
    monkeypatch.setattr(workflows.workflow, "execute_activity", execute_activity)

    await scheduler._run_fixed_phase("ocr", set())

    assert dispatched == [registration]


@pytest.mark.asyncio
async def test_ocr_phase_dispatches_only_current_tag_matches(monkeypatch):
    scheduler = workflows.ModelPhaseSchedulerWorkflow()
    first = DocumentPhaseRegistration("first", "archibot/document/1", 11)
    tagged = DocumentPhaseRegistration("tagged", "archibot/document/2", 12)
    scheduler._cycle = 1
    scheduler._cycle_configuration = replace(
        _configuration("embedding"), ocr_mode="text", ocr_requested_tag_id=124
    )
    scheduler._active_documents = {first.workflow_id: first, tagged.workflow_id: tagged}
    dispatched = []
    selection_requests = []

    async def project(*_args):
        return None

    async def execute_phase(_phase, _configuration, registrations):
        dispatched.extend(registrations)
        return set()

    async def execute_activity(activity_fn, argument, **kwargs):
        assert activity_fn is workflows.select_document_ocr_phase
        assert kwargs["task_queue"] == workflows.PAPERLESS_TASK_QUEUE
        selection_requests.append(argument)
        return [tagged]

    monkeypatch.setattr(scheduler, "_project", project)
    monkeypatch.setattr(scheduler, "_execute_document_phase", execute_phase)
    monkeypatch.setattr(workflows.workflow, "execute_activity", execute_activity)

    await scheduler._run_fixed_phase("ocr", set())

    assert selection_requests == [OcrPhaseSelectionRequest([first, tagged], 124)]
    assert dispatched == [tagged]


@pytest.mark.asyncio
async def test_ocr_selection_reads_current_paperless_tags(monkeypatch):
    registrations = [
        DocumentPhaseRegistration("first", "archibot/document/1", 11),
        DocumentPhaseRegistration("second", "archibot/document/2", 12),
    ]
    documents = {
        101: SimpleNamespace(id=101, tags=[7]),
        102: SimpleNamespace(id=102, tags=[7, 124]),
    }

    class Paperless:
        closed = False

        async def get_document(self, document_id):
            return documents[document_id]

        async def aclose(self):
            self.closed = True

    paperless = Paperless()
    monkeypatch.setattr(
        document_phase_activities,
        "_load_run",
        lambda pipeline_run_id: {"paperless_document_id": pipeline_run_id + 90},
    )
    monkeypatch.setattr(document_phase_activities, "PaperlessClient", lambda: paperless)
    monkeypatch.setattr(document_phase_activities.activity, "heartbeat", lambda _details: None)

    selected = await document_phase_activities.select_document_ocr_phase(
        OcrPhaseSelectionRequest(registrations, 124)
    )

    assert selected == [registrations[1]]
    assert paperless.closed is True


async def _async_configuration() -> ModelPhaseConfiguration:
    return _configuration("embedding")


@pytest.mark.asyncio
async def test_empty_embedding_generation_finishes_and_releases_phase(monkeypatch):
    calls = []
    signals = []
    workflow_instance = workflows.EmbeddingIndexWorkflow()
    workflow_instance.model_phase_grant(ModelPhaseGrant(3, _configuration("embedding")))

    async def execute(activity_fn, argument, **_kwargs):
        calls.append((activity_fn, argument))
        if activity_fn is workflows.prepare_embedding_generation:
            return PreparedEmbeddingBuild(9, 44, [])
        if activity_fn is workflows.finish_embedding_generation:
            assert argument == EmbeddingProgress(9, 44, 0, 0, 0, 0)
            return EmbeddingWorkflowResult(9, 44, 0, 0, 0, "complete")
        raise AssertionError(activity_fn)

    class Handle:
        async def signal(self, name, payload):
            signals.append((name, payload))

    async def wait_condition(predicate):
        assert predicate()

    async def scheduler_ready():
        return None

    monkeypatch.setattr(workflows, "_ensure_scheduler", scheduler_ready)
    monkeypatch.setattr(workflows.workflow, "patched", lambda _patch_id: True)
    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)
    monkeypatch.setattr(workflows.workflow, "wait_condition", wait_condition)
    monkeypatch.setattr(workflows.workflow, "get_external_workflow_handle", lambda _id: Handle())
    monkeypatch.setattr(
        workflows.workflow,
        "info",
        lambda: SimpleNamespace(workflow_id="archibot/embedding-index/9"),
    )

    result = await workflow_instance.run(EmbeddingWorkflowRequest(9))

    assert result.status == "complete"
    assert not any(call[0] is workflows.embed_document for call in calls)
    assert signals[-1] == (
        "embedding_index_completed",
        EmbeddingIndexPhaseResult(
            "archibot/embedding-index/9:embedding-index",
            "archibot/embedding-index/9",
            9,
            "complete",
        ),
    )


@pytest.mark.asyncio
async def test_embedding_patch_replays_the_original_activity_payload(monkeypatch):
    calls = []

    async def execute(activity_fn, argument, **_kwargs):
        calls.append((activity_fn, argument))
        if activity_fn is workflows.prepare_embedding_generation:
            return PreparedEmbeddingBuild(9, 44, [])
        if activity_fn is workflows.finish_embedding_generation:
            return EmbeddingWorkflowResult(9, 44, 0, 0, 0, "complete")
        raise AssertionError(activity_fn)

    monkeypatch.setattr(workflows.workflow, "patched", lambda _patch_id: False)
    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)

    result = await workflows.EmbeddingIndexWorkflow().run(EmbeddingWorkflowRequest(9))

    assert result.status == "complete"
    assert calls[0] == (
        workflows.prepare_embedding_generation,
        LegacyEmbeddingWorkflowRequest(9),
    )


@pytest.mark.asyncio
async def test_poll_patch_replays_the_original_document_child_payload(monkeypatch):
    child_calls = []

    async def execute(activity_fn, argument, **_kwargs):
        if activity_fn is workflows.discover_inbox_documents:
            return PollDiscoveryResult(
                command_id=5,
                documents_seen=1,
                documents_skipped=0,
                workflow_starts=[DocumentWorkflowStart(12, "archibot/document/261/version")],
                status="succeeded",
            )
        if activity_fn is workflows.finish_poll_discovery:
            return argument
        raise AssertionError(activity_fn)

    async def start_child(_workflow, payload, **kwargs):
        child_calls.append((payload, kwargs))

    monkeypatch.setattr(workflows.workflow, "patched", lambda _patch_id: False)
    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)
    monkeypatch.setattr(workflows.workflow, "start_child_workflow", start_child)

    result = await workflows.PollReconciliationWorkflow().run(PollWorkflowRequest(5))

    assert result == PollWorkflowResult(5, 1, 1, 0, "succeeded")
    assert child_calls[0][0] == LegacyDocumentWorkflowRequest(12)
