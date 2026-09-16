from __future__ import annotations

import asyncio
import inspect
from contextlib import nullcontext
from types import SimpleNamespace

import pytest

from app.models import PaperlessDocument
from app.temporal import embedding_activities
from app.temporal.contracts import (
    EmbeddingPreparationFailure,
    EmbeddingProgress,
    EmbeddingWorkflowRequest,
    EmbedDocumentRequest,
)


def test_registered_embedding_activities_are_async_worker_safe():
    activities = (
        embedding_activities.prepare_embedding_generation,
        embedding_activities.embed_document,
        embedding_activities.project_embedding_progress,
        embedding_activities.finish_embedding_generation,
        embedding_activities.fail_embedding_preparation,
    )

    assert all(inspect.iscoroutinefunction(item) for item in activities)


class _Paperless:
    def __init__(self, documents):
        self.documents = documents
        self.closed = False

    async def list_all_documents(self, *, limit=None):
        assert limit is None
        return self.documents

    async def get_document(self, document_id):
        return next(document for document in self.documents if document.id == document_id)

    async def aclose(self):
        self.closed = True


class _Provider:
    embed_model = "embed-model"

    def __init__(self):
        self.closed = False

    async def embed(self, text):
        await asyncio.sleep(0.01)
        return [0.1, 0.2]

    async def aclose(self):
        self.closed = True


class _Connection:
    def __init__(self, calls, rowcounts=None):
        self.calls = calls
        self.rowcounts = iter(rowcounts or [])

    def execute(self, statement, params=None):
        self.calls.append((statement, params or {}))
        result = SimpleNamespace(rowcount=next(self.rowcounts, 1))
        result.mappings = lambda: result
        result.all = lambda: []
        return result


class _Engine:
    def __init__(self, calls, rowcounts=None):
        self.connection = _Connection(calls, rowcounts)

    def begin(self):
        return nullcontext(self.connection)


@pytest.mark.asyncio
async def test_empty_embedding_population_completes_without_loading_ai_provider(monkeypatch):
    paperless = _Paperless([])
    projected = []
    provider_created = False

    def provider_factory():
        nonlocal provider_created
        provider_created = True
        return _Provider()

    monkeypatch.setattr(embedding_activities, "_prepare_build_projection", lambda _: 44)
    monkeypatch.setattr(embedding_activities, "_command_limit", lambda _: None)
    monkeypatch.setattr(embedding_activities, "PaperlessClient", lambda: paperless)
    monkeypatch.setattr(embedding_activities, "create_ai_provider", provider_factory)
    monkeypatch.setattr(embedding_activities, "_project_embedding_progress", projected.append)

    prepared = await embedding_activities.prepare_embedding_generation(
        EmbeddingWorkflowRequest(command_id=9)
    )

    assert prepared.document_ids == []
    assert projected == [EmbeddingProgress(9, 44, 0, 0, 0, 0)]
    assert paperless.closed is True
    assert provider_created is False


@pytest.mark.asyncio
async def test_embedding_activity_heartbeats_during_slow_model_call(monkeypatch):
    document = PaperlessDocument(id=261, title="Invoice", content="Payable")
    paperless = _Paperless([document])
    provider = _Provider()
    heartbeats = []

    monkeypatch.setattr(embedding_activities, "PaperlessClient", lambda: paperless)
    monkeypatch.setattr(embedding_activities, "create_ai_provider", lambda: provider)
    monkeypatch.setattr(embedding_activities, "is_trusted_document", lambda _: True)
    monkeypatch.setattr(embedding_activities, "document_embedding_exists", lambda **_: False)
    monkeypatch.setattr(embedding_activities, "store_document_embedding", lambda _: "hash")
    monkeypatch.setattr(embedding_activities.activity, "heartbeat", heartbeats.append)
    monkeypatch.setattr(embedding_activities, "_EMBEDDING_HEARTBEAT_SECONDS", 0.001)

    result = await embedding_activities.embed_document(EmbedDocumentRequest(44, 261))

    assert result.status == "embedded"
    assert len(heartbeats) >= 3
    assert all(item == {"paperless_document_id": 261} for item in heartbeats)
    assert paperless.closed is True
    assert provider.closed is True


@pytest.mark.asyncio
async def test_preparation_failure_terminates_command_and_build_projections(monkeypatch):
    calls = []
    monkeypatch.setattr(embedding_activities, "engine", lambda: _Engine(calls))
    monkeypatch.setattr(embedding_activities, "sql_text", lambda statement: statement)

    await embedding_activities.fail_embedding_preparation(
        EmbeddingPreparationFailure(
            command_id=9,
            error="Temporal embedding preparation exhausted its retries.",
        )
    )

    assert len(calls) == 2
    assert "UPDATE embedding_index_state" in calls[0][0]
    assert "status = 'failed'" in calls[0][0]
    assert "UPDATE commands" in calls[1][0]
    assert "status = 'failed_permanent'" in calls[1][0]
    assert calls[1][1]["command_id"] == 9


@pytest.mark.asyncio
async def test_empty_generation_finishes_as_complete_zero_of_zero(monkeypatch):
    calls = []
    monkeypatch.setattr(embedding_activities, "engine", lambda: _Engine(calls))
    monkeypatch.setattr(embedding_activities, "sql_text", lambda statement: statement)

    result = await embedding_activities.finish_embedding_generation(
        EmbeddingProgress(
            command_id=9,
            build_id=44,
            total=0,
            done=0,
            embedded=0,
            failed=0,
        )
    )

    assert result.status == "complete"
    assert result.total == 0
    assert result.embedded == 0
    assert calls[0][1]["status"] == "complete"
    assert calls[1][1]["status"] == "succeeded"


def test_progress_projection_fails_when_command_bound_build_is_missing(monkeypatch):
    calls = []
    monkeypatch.setattr(embedding_activities, "engine", lambda: _Engine(calls, [0]))
    monkeypatch.setattr(embedding_activities, "sql_text", lambda statement: statement)

    with pytest.raises(RuntimeError, match="missing or belongs to another command"):
        embedding_activities._project_embedding_progress(EmbeddingProgress(9, 44, 1, 1, 1, 0))

    assert len(calls) == 1


def test_finish_fails_when_command_projection_is_missing(monkeypatch):
    calls = []
    monkeypatch.setattr(embedding_activities, "engine", lambda: _Engine(calls, [1, 0]))
    monkeypatch.setattr(embedding_activities, "sql_text", lambda statement: statement)

    with pytest.raises(RuntimeError, match="Embedding command 9 is missing"):
        embedding_activities._finish_embedding_generation(EmbeddingProgress(9, 44, 0, 0, 0, 0))

    assert len(calls) == 2


def test_complete_build_releases_blocked_document_workflows(monkeypatch):
    calls = []
    row = {
        "id": 12,
        "temporal_workflow_id": "archibot/document/261",
        "paperless_document_id": 261,
    }

    class Result:
        rowcount = 1

        def mappings(self):
            return self

        def all(self):
            return [row]

    connection = SimpleNamespace(
        execute=lambda statement, params=None: calls.append((statement, params or {})) or Result()
    )
    monkeypatch.setattr(embedding_activities, "sql_text", lambda statement: statement)

    embedding_activities._release_blocked_document_workflows(connection, 44)

    assert len(calls) == 4
    assert "UPDATE pipeline_runs" in calls[1][0]
    assert "'start_workflow'" in calls[2][0]
    assert "'embedding_ready_v2'" in calls[3][0]
    assert calls[2][1]["workflow_id"] == "archibot/document/261"
