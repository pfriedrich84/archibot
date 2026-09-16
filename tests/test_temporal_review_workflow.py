from __future__ import annotations

import inspect
from typing import Any

import pytest
from temporalio.converter import DataConverter

from app.temporal import workflows
from app.temporal.contracts import (
    DocumentPhaseResult,
    DocumentProcessResult,
    DocumentReadiness,
    DocumentReviewCompletion,
    DocumentSupersession,
    DocumentWorkflowRequest,
    ModelPhaseConfiguration,
    ReviewCommitRequest,
    ReviewCommitResult,
)


def _configuration() -> ModelPhaseConfiguration:
    return ModelPhaseConfiguration(
        phase="judge",
        provider_type="ollama",
        provider_base_url="http://provider",
        model_id="judge",
        configuration_revision="revision",
        task_queue="archibot-model-judge",
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


def test_document_workflow_keeps_the_first_review_decision_signal():
    accepted = {"intent_id": "accepted", "payload": {"decision": "accepted"}}
    rejected = {"intent_id": "rejected", "payload": {"decision": "rejected"}}
    instance = workflows.DocumentWorkflow()

    instance.review_decision(accepted)
    instance.review_decision(rejected)

    assert instance._review_decision == accepted


@pytest.mark.asyncio
@pytest.mark.parametrize(
    "signal_name,payload",
    [
        (
            "review_decision_v2",
            {
                "decision": "accepted",
                "review_suggestion_id": 34,
                "command_id": 15,
                "temporal_workflow_id": "archibot/document/261",
                "actor_is_admin": True,
                "actor_user_id": 1,
            },
        ),
        ("force_reprocess_v2", {"review_suggestion_id": 34, "command_id": 16}),
        ("embedding_ready_v2", {"build_id": 9}),
    ],
)
async def test_document_signal_contract_decodes_outbox_envelopes(signal_name, payload):
    envelope = {"intent_id": "decision-id", "payload": payload}
    definition = workflows.DocumentWorkflow.__temporal_workflow_definition.signals[signal_name]

    encoded = await DataConverter.default.encode([envelope])
    decoded = await DataConverter.default.decode(encoded, definition.arg_types)

    assert definition.arg_types == [dict[str, Any]]
    assert decoded == [envelope]


def test_legacy_document_signal_contracts_remain_replay_compatible():
    signals = workflows.DocumentWorkflow.__temporal_workflow_definition.signals

    assert signals["review_decision"].arg_types == [dict[str, object]]
    assert signals["force_reprocess"].arg_types == [dict[str, object]]
    assert signals["embedding_ready"].arg_types == [dict[str, object]]


def test_new_temporal_activity_retry_policies_are_bounded():
    assert "maximum_attempts=0" not in inspect.getsource(workflows.DocumentWorkflow)
    assert "maximum_attempts=0" not in inspect.getsource(workflows.EmbeddingIndexWorkflow)
    assert "maximum_attempts=0" not in inspect.getsource(workflows._execute_review_commit)


@pytest.mark.asyncio
async def test_document_workflow_commits_an_accepted_review_signal(monkeypatch):
    commit_request = ReviewCommitRequest(34, 15, "archibot/document/261/version")
    calls = []

    async def execute(activity_fn, argument, **_kwargs):
        calls.append((activity_fn, argument))
        if activity_fn is workflows.commit_review_suggestion:
            return ReviewCommitResult(34, 15, "committed", ["title"])
        if activity_fn is workflows.finish_document_review:
            return None
        raise AssertionError(activity_fn)

    async def wait_condition(predicate):
        assert predicate()

    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)
    monkeypatch.setattr(workflows.workflow, "wait_condition", wait_condition)
    instance = workflows.DocumentWorkflow()
    instance.review_decision(
        {
            "intent_id": "decision-id",
            "payload": {
                "decision": "accepted",
                "review_suggestion_id": 34,
                "command_id": 15,
                "temporal_workflow_id": "archibot/document/261/version",
            },
        }
    )

    result = await instance._finish_review(DocumentWorkflowRequest(12, "archibot/document/261"), 34)

    assert result.status == "committed"
    assert calls[-2] == (workflows.commit_review_suggestion, commit_request)
    assert calls[-1] == (
        workflows.finish_document_review,
        DocumentReviewCompletion(12, 34, "committed"),
    )


@pytest.mark.asyncio
async def test_document_workflow_rejection_never_calls_paperless_commit(monkeypatch):
    async def execute(activity_fn, argument, **_kwargs):
        assert activity_fn is workflows.finish_document_review
        return None

    async def wait_condition(predicate):
        assert predicate()

    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)
    monkeypatch.setattr(workflows.workflow, "wait_condition", wait_condition)
    instance = workflows.DocumentWorkflow()
    instance.review_decision(
        {
            "intent_id": "decision-id",
            "payload": {
                "decision": "rejected",
                "review_suggestion_id": 34,
                "command_id": None,
                "temporal_workflow_id": "archibot/document/261/version",
            },
        }
    )

    result = await instance._finish_review(DocumentWorkflowRequest(12, "archibot/document/261"), 34)

    assert result.status == "rejected"


@pytest.mark.asyncio
async def test_new_document_workflow_owns_all_phases_and_waits_for_review(monkeypatch):
    calls = []
    task_queues = []

    async def execute(activity_fn, argument, **kwargs):
        calls.append((activity_fn, argument))
        task_queues.append(kwargs.get("task_queue"))
        if activity_fn is workflows.check_document_readiness:
            return DocumentReadiness(12, "ready")
        if activity_fn is workflows.load_model_phase_configuration:
            return _configuration()
        if activity_fn in {
            workflows.process_document_ocr_phase,
            workflows.process_document_embedding_phase,
            workflows.process_document_classification_phase,
            workflows.process_document_judge_phase,
        }:
            return DocumentPhaseResult(12, argument.configuration.phase, "completed")
        if activity_fn is workflows.publish_document_review:
            return DocumentProcessResult(12, 34, "succeeded")
        if activity_fn is workflows.finish_document_review:
            assert argument == DocumentReviewCompletion(12, 34, "rejected")
            return None
        raise AssertionError(activity_fn)

    async def wait_condition(predicate):
        assert predicate()

    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)
    monkeypatch.setattr(workflows.workflow, "wait_condition", wait_condition)
    instance = workflows.DocumentWorkflow()
    instance.review_decision(
        {
            "intent_id": "decision-id",
            "payload": {
                "decision": "rejected",
                "review_suggestion_id": 34,
                "command_id": None,
                "temporal_workflow_id": "archibot/document/261",
            },
        }
    )

    result = await instance.run(DocumentWorkflowRequest(12, "archibot/document/261"))

    assert result.status == "rejected"
    assert [call[0] for call in calls] == [
        workflows.check_document_readiness,
        workflows.load_model_phase_configuration,
        workflows.process_document_ocr_phase,
        workflows.process_document_embedding_phase,
        workflows.process_document_classification_phase,
        workflows.process_document_judge_phase,
        workflows.publish_document_review,
        workflows.finish_document_review,
    ]
    assert task_queues[2:6] == [
        workflows.MODEL_TASK_QUEUE,
        workflows.MODEL_TASK_QUEUE,
        workflows.MODEL_TASK_QUEUE,
        workflows.MODEL_TASK_QUEUE,
    ]


def test_document_phase_search_attributes_are_operator_visible(monkeypatch):
    updates = []
    monkeypatch.setattr(workflows.workflow, "in_workflow", lambda: True)
    monkeypatch.setattr(workflows.workflow, "patched", lambda _patch: True)
    monkeypatch.setattr(workflows.workflow, "upsert_search_attributes", updates.append)

    workflows._upsert_document_search_attributes(
        "classification",
        DocumentWorkflowRequest(12, "archibot/document/261", 261),
    )

    assert [(update.key.name, update.value) for update in updates[0]] == [
        ("ArchiBotPhase", "classification"),
        ("ArchiBotPipelineRunId", 12),
        ("ArchiBotDocumentId", 261),
    ]


@pytest.mark.asyncio
async def test_embedding_gate_waits_for_signal_without_timer_polling(monkeypatch):
    readiness = iter(
        [
            DocumentReadiness(12, "waiting"),
            DocumentReadiness(12, "ready"),
        ]
    )
    instance = workflows.DocumentWorkflow()

    async def execute(activity_fn, _argument, **_kwargs):
        assert activity_fn is workflows.check_document_readiness
        return next(readiness)

    async def wait_condition(predicate):
        assert predicate() is False
        instance.embedding_ready({"embedding_build_id": 44})
        assert predicate() is True

    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)
    monkeypatch.setattr(workflows.workflow, "wait_condition", wait_condition)

    result = await instance._readiness(DocumentWorkflowRequest(12, "archibot/document/261", 261))

    assert result is None


@pytest.mark.asyncio
async def test_force_reprocess_supersedes_waiting_document_workflow(monkeypatch):
    calls = []

    class ContinuedAsNew(Exception):
        pass

    async def execute(activity_fn, argument, **_kwargs):
        calls.append((activity_fn, argument))
        return None

    async def wait_condition(predicate):
        assert predicate()

    def continue_as_new(request):
        assert request == DocumentWorkflowRequest(13, "archibot/document/261", 261)
        raise ContinuedAsNew

    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)
    monkeypatch.setattr(workflows.workflow, "wait_condition", wait_condition)
    monkeypatch.setattr(workflows.workflow, "continue_as_new", continue_as_new)
    instance = workflows.DocumentWorkflow()
    instance.force_reprocess(
        {
            "intent_id": "force-id",
            "payload": {
                "review_suggestion_id": 34,
                "replacement_pipeline_run_id": 13,
                "replacement_temporal_workflow_id": "archibot/document/261",
            },
        }
    )

    with pytest.raises(ContinuedAsNew):
        await instance._finish_review(DocumentWorkflowRequest(12, "archibot/document/261", 261), 34)

    assert calls == [(workflows.supersede_document_processing, DocumentSupersession(12, 13))]


def test_signal_with_start_for_current_generation_does_not_self_supersede():
    instance = workflows.DocumentWorkflow()
    instance.force_reprocess(
        {
            "intent_id": "force-id",
            "payload": {
                "replacement_pipeline_run_id": 13,
                "replacement_temporal_workflow_id": "archibot/document/261",
            },
        }
    )

    assert (
        instance._replacement_request(DocumentWorkflowRequest(13, "archibot/document/261", 261))
        is None
    )
    assert instance._force_reprocess is None


def test_force_reprocess_signal_keeps_the_newest_pipeline_generation():
    instance = workflows.DocumentWorkflow()
    for pipeline_run_id in (13, 14, 13):
        instance.force_reprocess(
            {
                "intent_id": f"force-{pipeline_run_id}",
                "payload": {
                    "replacement_pipeline_run_id": pipeline_run_id,
                    "replacement_temporal_workflow_id": "archibot/document/261",
                },
            }
        )

    assert instance._force_reprocess_pipeline_run_id(instance._force_reprocess) == 14


@pytest.mark.asyncio
async def test_force_reprocess_supersedes_generations_arriving_during_projection(monkeypatch):
    calls = []

    class ContinuedAsNew(Exception):
        pass

    instance = workflows.DocumentWorkflow()

    async def execute(activity_fn, argument, **_kwargs):
        calls.append((activity_fn, argument))
        if len(calls) == 1:
            instance.force_reprocess(
                {
                    "intent_id": "force-14",
                    "payload": {
                        "replacement_pipeline_run_id": 14,
                        "replacement_temporal_workflow_id": "archibot/document/261",
                    },
                }
            )

    def continue_as_new(request):
        assert request == DocumentWorkflowRequest(14, "archibot/document/261", 261)
        raise ContinuedAsNew

    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)
    monkeypatch.setattr(workflows.workflow, "continue_as_new", continue_as_new)
    instance.force_reprocess(
        {
            "intent_id": "force-13",
            "payload": {
                "replacement_pipeline_run_id": 13,
                "replacement_temporal_workflow_id": "archibot/document/261",
            },
        }
    )

    with pytest.raises(ContinuedAsNew):
        await instance._continue_if_reprocessed(
            DocumentWorkflowRequest(12, "archibot/document/261", 261)
        )

    assert calls == [
        (workflows.supersede_document_processing, DocumentSupersession(12, 13)),
        (workflows.supersede_document_processing, DocumentSupersession(13, 14)),
    ]


@pytest.mark.asyncio
async def test_legacy_force_signal_replays_the_separate_generation_behavior(monkeypatch):
    calls = []

    async def execute(activity_fn, argument, **_kwargs):
        calls.append((activity_fn, argument))

    async def wait_condition(predicate):
        assert predicate()

    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)
    monkeypatch.setattr(workflows.workflow, "wait_condition", wait_condition)
    instance = workflows.DocumentWorkflow()
    instance.force_reprocess(
        {
            "intent_id": "legacy-force",
            "payload": {
                "review_suggestion_id": 34,
                "replacement_pipeline_run_id": 13,
                "replacement_temporal_workflow_id": "archibot/document/261/reprocess/13",
            },
        }
    )

    result = await instance._finish_review(
        DocumentWorkflowRequest(12, "archibot/document/261", 261), 34
    )

    assert result.status == "superseded"
    assert calls == [
        (workflows.finish_document_review, DocumentReviewCompletion(12, 34, "superseded"))
    ]


@pytest.mark.asyncio
async def test_permanent_document_phase_result_fails_owning_workflow(monkeypatch):
    calls = []

    async def execute(activity_fn, argument, **_kwargs):
        calls.append((activity_fn, argument))
        if activity_fn is workflows.check_document_readiness:
            return DocumentReadiness(12, "ready")
        if activity_fn is workflows.load_model_phase_configuration:
            return _configuration()
        if activity_fn is workflows.process_document_ocr_phase:
            return DocumentPhaseResult(12, "ocr", "failed_permanent")
        if activity_fn is workflows.fail_document_processing:
            return None
        raise AssertionError(activity_fn)

    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)

    with pytest.raises(RuntimeError, match="ocr phase failed permanently"):
        await workflows.DocumentWorkflow().run(DocumentWorkflowRequest(12, "archibot/document/261"))

    assert calls[-1] == (workflows.fail_document_processing, 12)
