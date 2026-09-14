from __future__ import annotations

import inspect

import pytest

from app.temporal import workflows
from app.temporal.contracts import (
    DocumentProcessResult,
    DocumentWorkflowRequest,
    ModelPhaseConfiguration,
    ReviewCommitRequest,
    ReviewCommitResult,
    ReviewRelease,
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


class _SchedulerHandle:
    async def signal(self, *_args):
        return None


def test_document_workflow_keeps_the_first_review_decision_signal():
    accepted = {"intent_id": "accepted", "payload": {"decision": "accepted"}}
    rejected = {"intent_id": "rejected", "payload": {"decision": "rejected"}}
    instance = workflows.DocumentWorkflow()

    instance.review_decision(accepted)
    instance.review_decision(rejected)

    assert instance._review_decision == accepted


def test_new_temporal_activity_retry_policies_are_bounded():
    assert "maximum_attempts=0" not in inspect.getsource(workflows.ModelPhaseSchedulerWorkflow)
    assert "maximum_attempts=0" not in inspect.getsource(workflows._execute_review_commit)


@pytest.mark.asyncio
async def test_document_workflow_commits_an_accepted_review_signal(monkeypatch):
    commit_request = ReviewCommitRequest(34, 15, "archibot/document/261/version")
    calls = []

    async def execute(activity_fn, argument, **_kwargs):
        calls.append((activity_fn, argument))
        if activity_fn is workflows.publish_document_review:
            return DocumentProcessResult(12, 34, "succeeded")
        if activity_fn is workflows.commit_review_suggestion:
            return ReviewCommitResult(34, 15, "committed", ["title"])
        raise AssertionError(activity_fn)

    async def wait_condition(predicate):
        assert predicate()

    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)
    monkeypatch.setattr(workflows.workflow, "wait_condition", wait_condition)
    monkeypatch.setattr(workflows.workflow, "patched", lambda _patch_id: True)
    monkeypatch.setattr(workflows, "_ensure_scheduler", lambda: _async_none())
    monkeypatch.setattr(
        workflows.workflow, "get_external_workflow_handle", lambda _workflow_id: _SchedulerHandle()
    )
    instance = workflows.DocumentWorkflow()
    instance.review_release(ReviewRelease(7, _configuration()))
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

    result = await instance.run(DocumentWorkflowRequest(12, "archibot/document/261"))

    assert result.status == "committed"
    assert calls[-1] == (workflows.commit_review_suggestion, commit_request)


@pytest.mark.asyncio
async def test_document_workflow_rejection_never_calls_paperless_commit(monkeypatch):
    async def execute(activity_fn, argument, **_kwargs):
        assert activity_fn is workflows.publish_document_review
        return DocumentProcessResult(12, 34, "succeeded")

    async def wait_condition(predicate):
        assert predicate()

    monkeypatch.setattr(workflows.workflow, "execute_activity", execute)
    monkeypatch.setattr(workflows.workflow, "wait_condition", wait_condition)
    monkeypatch.setattr(workflows.workflow, "patched", lambda _patch_id: True)
    monkeypatch.setattr(workflows, "_ensure_scheduler", lambda: _async_none())
    monkeypatch.setattr(
        workflows.workflow, "get_external_workflow_handle", lambda _workflow_id: _SchedulerHandle()
    )
    instance = workflows.DocumentWorkflow()
    instance.review_release(ReviewRelease(7, _configuration()))
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

    result = await instance.run(DocumentWorkflowRequest(12, "archibot/document/261"))

    assert result.status == "rejected"


async def _async_none() -> None:
    return None
