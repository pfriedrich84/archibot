from __future__ import annotations

from unittest.mock import AsyncMock, Mock

import pytest
from temporalio import workflow
from temporalio.common import WorkflowIDReusePolicy
from temporalio.exceptions import WorkflowAlreadyStartedError
from temporalio.worker.workflow_sandbox import SandboxedWorkflowRunner

from app.temporal import phase_activities, workflows
from app.temporal.contracts import (
    DocumentWorkflowRequest,
    DocumentWorkflowStart,
    EmbeddingProgress,
    EmbeddingWorkflowRequest,
    EmbeddingWorkflowResult,
    ModelPhaseConfiguration,
    PollDiscoveryResult,
    PollWorkflowRequest,
    PollWorkflowResult,
    PreparedEmbeddingBuild,
)


def _configuration(phase: str = "embedding") -> ModelPhaseConfiguration:
    return ModelPhaseConfiguration(
        phase=phase,
        provider_type="ollama",
        provider_base_url="http://provider",
        model_id=f"{phase}-model",
        configuration_revision="full-configuration-revision",
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


def test_phase_configuration_pins_models_and_context_windows(monkeypatch):
    monkeypatch.setattr(phase_activities.settings, "ollama_embed_num_ctx", 2048)
    monkeypatch.setattr(phase_activities.settings, "ollama_num_ctx", 8192)
    monkeypatch.setattr(phase_activities.settings, "ollama_ocr_num_ctx", 16384)

    configuration = phase_activities._load_model_phase_configuration("classification")

    assert configuration.embedding_num_ctx == 2048
    assert configuration.classification_num_ctx == 8192
    assert configuration.ocr_num_ctx == 16384


def test_configuration_selects_each_role_without_changing_snapshot():
    snapshot = _configuration()

    classification = workflows._configuration_for_phase(snapshot, "classification")
    judge = workflows._configuration_for_phase(snapshot, "judge")

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
        workflows.EmbeddingIndexWorkflow,
        workflows.DocumentWorkflow,
        workflows.ReviewCommitWorkflow,
        workflows.PollReconciliationWorkflow,
        workflows.ScheduledPollReconciliationWorkflow,
    ):
        runner.prepare_workflow(workflow._Definition.must_from_class(workflow_class))


@pytest.mark.asyncio
async def test_embedding_generation_runs_directly(monkeypatch):
    calls = []

    async def execute(activity_fn, argument, **_kwargs):
        calls.append((activity_fn, argument))
        if activity_fn is workflows.load_model_phase_configuration:
            return _configuration()
        if activity_fn is workflows.prepare_embedding_generation:
            return PreparedEmbeddingBuild(9, 44, [])
        if activity_fn is workflows.finish_embedding_generation:
            assert argument == EmbeddingProgress(9, 44, 0, 0, 0, 0)
            return EmbeddingWorkflowResult(9, 44, 0, 0, 0, "complete")
        raise AssertionError(activity_fn)

    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)

    result = await workflows.EmbeddingIndexWorkflow().run(EmbeddingWorkflowRequest(9))

    assert result.status == "complete"
    assert calls[0] == (workflows.load_model_phase_configuration, "embedding")
    assert calls[1] == (
        workflows.prepare_embedding_generation,
        EmbeddingWorkflowRequest(
            9, workflows._serialized_configuration_for_phase(_configuration(), "embedding")
        ),
    )
    assert not any(call[0] is workflows.embed_document for call in calls)


@pytest.mark.asyncio
async def test_full_reindex_starts_forced_workflow_for_every_document(monkeypatch):
    child_calls = []

    async def execute(activity_fn, argument, **_kwargs):
        if activity_fn is workflows.load_model_phase_configuration:
            return _configuration()
        if activity_fn is workflows.prepare_embedding_generation:
            return PreparedEmbeddingBuild(9, 44, [])
        if activity_fn is workflows.finish_embedding_generation:
            return EmbeddingWorkflowResult(9, 44, 0, 0, 0, "complete")
        if activity_fn is workflows.discover_reindex_documents:
            return PollDiscoveryResult(
                9,
                1,
                0,
                [DocumentWorkflowStart(12, "archibot/document/261", 261, True)],
                "succeeded",
            )
        if activity_fn is workflows.finish_reindex_discovery:
            assert argument == PollWorkflowResult(9, 1, 1, 0, "succeeded")
            return argument
        raise AssertionError(activity_fn)

    async def start_child(_workflow, payload, **kwargs):
        child_calls.append((payload, kwargs))

    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)
    monkeypatch.setattr(workflows.workflow, "start_child_workflow", start_child)

    result = await workflows.EmbeddingIndexWorkflow().run(
        EmbeddingWorkflowRequest(9, rescan_all=True)
    )

    assert result.status == "complete"
    assert child_calls == [
        (
            DocumentWorkflowRequest(12, "archibot/document/261", 261),
            {
                "id": "archibot/document/261",
                "parent_close_policy": workflow.ParentClosePolicy.ABANDON,
                "id_reuse_policy": WorkflowIDReusePolicy.ALLOW_DUPLICATE,
            },
        )
    ]


@pytest.mark.asyncio
async def test_poll_starts_direct_document_workflow_payload(monkeypatch):
    child_calls = []

    async def execute(activity_fn, argument, **_kwargs):
        if activity_fn is workflows.discover_inbox_documents:
            return PollDiscoveryResult(
                command_id=5,
                documents_seen=1,
                documents_skipped=0,
                workflow_starts=[DocumentWorkflowStart(12, "archibot/document/261")],
                status="succeeded",
            )
        if activity_fn is workflows.finish_poll_discovery:
            return argument
        raise AssertionError(activity_fn)

    async def start_child(_workflow, payload, **kwargs):
        child_calls.append((payload, kwargs))

    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)
    monkeypatch.setattr(workflows.workflow, "start_child_workflow", start_child)

    result = await workflows.PollReconciliationWorkflow().run(PollWorkflowRequest(5))

    assert result == PollWorkflowResult(5, 1, 1, 0, "succeeded")
    assert child_calls[0][0] == DocumentWorkflowRequest(12, "archibot/document/261")


@pytest.mark.asyncio
async def test_force_poll_signals_running_stable_document_workflow(monkeypatch):
    async def execute(activity_fn, argument, **_kwargs):
        if activity_fn is workflows.discover_inbox_documents:
            return PollDiscoveryResult(
                command_id=5,
                documents_seen=1,
                documents_skipped=0,
                workflow_starts=[DocumentWorkflowStart(13, "archibot/document/261", 261, True)],
                status="succeeded",
            )
        if activity_fn is workflows.finish_poll_discovery:
            return argument
        raise AssertionError(activity_fn)

    async def start_child(_workflow, payload, **kwargs):
        assert payload == DocumentWorkflowRequest(13, "archibot/document/261", 261)
        assert kwargs["id_reuse_policy"] is WorkflowIDReusePolicy.ALLOW_DUPLICATE
        raise WorkflowAlreadyStartedError("archibot/document/261", "archibot.document")

    handle = Mock()
    handle.signal = AsyncMock()
    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)
    monkeypatch.setattr(workflows.workflow, "start_child_workflow", start_child)
    monkeypatch.setattr(
        workflows.workflow, "get_external_workflow_handle", lambda _workflow_id: handle
    )

    result = await workflows.PollReconciliationWorkflow().run(PollWorkflowRequest(5))

    assert result == PollWorkflowResult(5, 1, 1, 0, "succeeded")
    handle.signal.assert_awaited_once_with(
        "force_reprocess_v2",
        {
            "intent_id": "poll-force:5:13",
            "payload": {
                "replacement_pipeline_run_id": 13,
                "replacement_temporal_workflow_id": "archibot/document/261",
            },
        },
    )
