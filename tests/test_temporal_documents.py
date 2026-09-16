from __future__ import annotations

import hashlib
import inspect
import json
from contextlib import nullcontext
from datetime import UTC, datetime
from types import SimpleNamespace

import pytest

from app.temporal import document_activities
from app.temporal.contracts import (
    DocumentWorkflowStart,
    PollWorkflowRequest,
    ScheduledPollStart,
)


def test_registered_document_activities_are_async_worker_safe():
    activities = (
        document_activities.create_scheduled_poll_command,
        document_activities.discover_inbox_documents,
        document_activities.finish_poll_discovery,
        document_activities.fail_poll_discovery,
        document_activities.check_document_readiness,
        document_activities.fail_document_processing,
    )

    assert all(inspect.iscoroutinefunction(item) for item in activities)


def test_poll_document_identity_matches_laravel_canonical_vector():
    modified = document_activities._modified_value(datetime(2026, 5, 8, 14, 0, tzinfo=UTC))

    assert modified == "2026-05-08T14:00:00.000000Z"
    assert (
        document_activities._document_dedupe_key(42, modified)
        == hashlib.sha256(b"42:2026-05-08T14:00:00.000000Z:unknown_content:v1").hexdigest()
    )


class _Paperless:
    def __init__(self, documents):
        self.documents = documents
        self.closed = False

    async def list_inbox_documents(self, tag_id):
        assert tag_id == 7
        return self.documents

    async def get_document(self, document_id):
        return next(document for document in self.documents if document.id == document_id)

    async def aclose(self):
        self.closed = True


class _QueryResult:
    def __init__(self, row):
        self.row = row

    def mappings(self):
        return self

    def first(self):
        return self.row

    def one(self):
        return self.row


class _WriteConnection:
    def __init__(self):
        self.calls = []

    def execute(self, statement, parameters=None):
        parameters = parameters or {}
        self.calls.append((statement, parameters))
        if "SELECT status FROM embedding_index_state" in statement:
            return _QueryResult({"status": "stale"})
        if "SELECT id, status, orchestration_driver" in statement:
            return _QueryResult(
                {
                    "id": 34,
                    "status": "blocked",
                    "orchestration_driver": "temporal",
                    "temporal_workflow_id": "archibot/document/261/reprocess/poll-5",
                }
            )
        return SimpleNamespace(rowcount=1)


class _WriteEngine:
    def __init__(self):
        self.connection = _WriteConnection()

    def begin(self):
        return nullcontext(self.connection)


@pytest.mark.asyncio
async def test_poll_discovery_skips_existing_reviews_and_returns_global_workflow_starts(
    monkeypatch,
):
    documents = [
        SimpleNamespace(id=1, modified=datetime(2026, 5, 8, tzinfo=UTC)),
        SimpleNamespace(id=2, modified=datetime(2026, 5, 9, tzinfo=UTC)),
    ]
    paperless = _Paperless(documents)
    persisted = []
    monkeypatch.setattr(document_activities.settings, "paperless_inbox_tag_id", 7)
    monkeypatch.setattr(document_activities, "_load_poll_command", lambda _: (None, False))
    monkeypatch.setattr(document_activities, "PaperlessClient", lambda: paperless)
    monkeypatch.setattr(document_activities, "classified_document_ids", lambda _: {2})

    def persist(**kwargs):
        persisted.append(kwargs)
        return DocumentWorkflowStart(11, "archibot/document/1/version", 1)

    monkeypatch.setattr(document_activities, "_persist_observation_and_run", persist)

    result = await document_activities.discover_inbox_documents(PollWorkflowRequest(5))

    assert result.documents_seen == 2
    assert result.documents_skipped == 1
    assert result.workflow_starts == [DocumentWorkflowStart(11, "archibot/document/1/version", 1)]
    assert persisted[0]["command_id"] == 5
    assert persisted[0]["paperless_document_id"] == 1
    assert paperless.closed is True


@pytest.mark.asyncio
async def test_empty_instance_without_inbox_tag_finishes_discovery_without_paperless(
    monkeypatch,
):
    monkeypatch.setattr(document_activities.settings, "paperless_inbox_tag_id", 0)
    monkeypatch.setattr(document_activities, "_load_poll_command", lambda _: (None, False))
    monkeypatch.setattr(
        document_activities,
        "PaperlessClient",
        lambda: pytest.fail("Paperless must not be opened without an inbox tag"),
    )

    result = await document_activities.discover_inbox_documents(PollWorkflowRequest(5))

    assert result.status == "skipped"
    assert result.documents_seen == 0
    assert result.workflow_starts == []


def test_poll_persists_recoverable_embedding_block_reason(monkeypatch):
    fake_engine = _WriteEngine()
    monkeypatch.setattr(document_activities, "engine", lambda: fake_engine)
    monkeypatch.setattr(document_activities, "sql_text", lambda statement: statement)

    result = document_activities._persist_observation_and_run(
        command_id=5,
        paperless_document_id=261,
        modified="2026-09-15T09:40:00.000000Z",
        force=True,
    )

    assert result is None
    insert = next(
        parameters
        for statement, parameters in fake_engine.connection.calls
        if "INSERT INTO pipeline_runs" in statement
    )
    assert insert["status"] == "blocked"
    assert insert["error_type"] == "embedding_index_not_ready"
    assert insert["error"] == "Waiting for embedding index to complete."


class _ScheduledResult:
    def __init__(self, *, row=None, scalar=None):
        self.row = row
        self.scalar = scalar

    def first(self):
        return self.row

    def scalar_one(self):
        return self.scalar

    def scalar_one_or_none(self):
        return self.scalar


class _ScheduledConnection:
    def __init__(self):
        self.calls = []

    def execute(self, statement, parameters=None):
        parameters = parameters or {}
        self.calls.append((statement, parameters))
        if "payload->>'temporal_run_id'" in statement:
            return _ScheduledResult(scalar=None)
        if "SELECT 1 FROM commands" in statement:
            return _ScheduledResult(row=None)
        if "INSERT INTO commands" in statement:
            return _ScheduledResult(scalar=42)
        return _ScheduledResult()


def test_temporal_schedule_creates_auditable_poll_command(monkeypatch):
    connection = _ScheduledConnection()
    database = SimpleNamespace(begin=lambda: nullcontext(connection))
    monkeypatch.setattr(document_activities, "engine", lambda: database)
    monkeypatch.setattr(document_activities, "sql_text", lambda statement: statement)
    monkeypatch.setattr(document_activities, "poll_interval_seconds", lambda: 600)

    request = document_activities._create_scheduled_poll_command(
        ScheduledPollStart("archibot/poll-reconciliation/run", "run-123")
    )

    assert request == PollWorkflowRequest(42)
    command_payload = next(
        json.loads(parameters["payload"])
        for statement, parameters in connection.calls
        if "INSERT INTO commands" in statement
    )
    assert command_payload == {
        "source": "temporal_schedule",
        "interval_seconds": 600,
        "orchestration_driver": "temporal",
        "temporal_workflow_id": "archibot/poll-reconciliation/run",
        "temporal_run_id": "run-123",
    }


def test_temporal_schedule_activity_retry_reuses_its_command(monkeypatch):
    connection = _ScheduledConnection()
    original_execute = connection.execute

    def execute(statement, parameters=None):
        if "payload->>'temporal_run_id'" in statement:
            connection.calls.append((statement, parameters or {}))
            return _ScheduledResult(scalar=42)
        return original_execute(statement, parameters)

    connection.execute = execute
    database = SimpleNamespace(begin=lambda: nullcontext(connection))
    monkeypatch.setattr(document_activities, "engine", lambda: database)
    monkeypatch.setattr(document_activities, "sql_text", lambda statement: statement)
    monkeypatch.setattr(document_activities, "poll_interval_seconds", lambda: 600)

    request = document_activities._create_scheduled_poll_command(
        ScheduledPollStart("archibot/poll-reconciliation/run", "run-123")
    )

    assert request == PollWorkflowRequest(42)
    assert not any("INSERT INTO commands" in statement for statement, _ in connection.calls)
