from datetime import UTC, datetime
from types import SimpleNamespace

import pytest

from app.actors import maintenance
from app.jobs.actor_execution import ActorExecutionHandle


def _actor(monkeypatch, actor_id=7):
    monkeypatch.setattr(
        maintenance,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=actor_id, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )


def test_modified_value_serializes_parsed_datetime_as_iso_8601():
    document = SimpleNamespace(modified=datetime(2026, 5, 8, 12, tzinfo=UTC))

    assert maintenance._modified_value(document) == "2026-05-08T12:00:00+00:00"


def test_poll_reconciliation_persists_marked_and_unmarked_candidates(monkeypatch):
    candidates = []
    events = []
    progresses = []
    monkeypatch.setattr(maintenance.settings, "paperless_inbox_tag_id", 123)
    _actor(monkeypatch)

    async def fake_fetch():
        return [
            SimpleNamespace(id=42, modified="2026-05-08T12:00:00Z"),
            SimpleNamespace(id=43, modified=None),
        ]

    monkeypatch.setattr(maintenance, "_fetch_inbox_documents", fake_fetch)
    monkeypatch.setattr(maintenance, "classified_document_ids", lambda ids: {42})
    monkeypatch.setattr(
        maintenance,
        "persist_poll_candidate",
        lambda **kwargs: candidates.append(kwargs) or SimpleNamespace(created=True),
    )
    monkeypatch.setattr(
        maintenance,
        "update_actor_execution_progress",
        lambda *args, **kwargs: progresses.append((args, kwargs)),
    )
    monkeypatch.setattr(
        maintenance, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )
    monkeypatch.setattr(maintenance, "finish_actor_execution", lambda *args, **kwargs: None)

    maintenance._reconcile_inbox_documents_impl(command_id=55)

    assert candidates == [
        {
            "command_id": 55,
            "paperless_document_id": 42,
            "discovered_modified": "2026-05-08T12:00:00Z",
            "marker_disposition": "already_classified",
            "force": False,
        },
        {
            "command_id": 55,
            "paperless_document_id": 43,
            "discovered_modified": None,
            "marker_disposition": "unclassified",
            "force": False,
        },
    ]
    assert progresses[-1][0][1].done == 2
    assert events[-1][1]["payload"] == {
        "documents_seen": 2,
        "candidates_persisted": 2,
        "candidates_replayed": 0,
        "documents_marked_already_classified": 1,
        "force": False,
    }


def test_forced_poll_bypasses_marker_and_persists_force_metadata(monkeypatch):
    candidates = []
    monkeypatch.setattr(maintenance.settings, "paperless_inbox_tag_id", 123)
    _actor(monkeypatch, None)

    async def fake_fetch():
        return [SimpleNamespace(id=42, modified="2026-05-08T12:00:00Z")]

    monkeypatch.setattr(maintenance, "_fetch_inbox_documents", fake_fetch)
    monkeypatch.setattr(
        maintenance,
        "classified_document_ids",
        lambda ids: (_ for _ in ()).throw(AssertionError("forced poll loaded markers")),
    )
    monkeypatch.setattr(
        maintenance,
        "persist_poll_candidate",
        lambda **kwargs: candidates.append(kwargs) or SimpleNamespace(created=True),
    )
    monkeypatch.setattr(maintenance, "publish_pipeline_event", lambda *args, **kwargs: None)
    monkeypatch.setattr(maintenance, "finish_actor_execution", lambda *args, **kwargs: None)

    maintenance._reconcile_inbox_documents_impl(force=True, command_id=56)

    assert candidates == [
        {
            "command_id": 56,
            "paperless_document_id": 42,
            "discovered_modified": "2026-05-08T12:00:00Z",
            "marker_disposition": "unclassified",
            "force": True,
        }
    ]


def test_poll_reconciliation_schedules_retry_for_transient_fetch_failure(monkeypatch):
    retries = []
    monkeypatch.setattr(maintenance.settings, "paperless_inbox_tag_id", 123)
    _actor(monkeypatch)

    async def fake_fetch():
        raise TimeoutError("paperless slow")

    monkeypatch.setattr(maintenance, "_fetch_inbox_documents", fake_fetch)
    monkeypatch.setattr(maintenance, "update_actor_execution_progress", lambda *a, **k: None)
    monkeypatch.setattr(
        "app.execution_lifecycle.execution_store.schedule_actor_execution_retry",
        lambda *a, **k: retries.append((a, k)),
    )
    monkeypatch.setattr(maintenance, "publish_pipeline_event", lambda *a, **k: None)

    with pytest.raises(TimeoutError):
        maintenance._reconcile_inbox_documents_impl(command_id=57)

    assert retries[0][1]["retry_class"] == "transient_network"


def test_poll_reconciliation_skips_without_inbox_tag(monkeypatch):
    finishes = []
    monkeypatch.setattr(maintenance.settings, "paperless_inbox_tag_id", 0)
    _actor(monkeypatch)
    monkeypatch.setattr(maintenance, "update_actor_execution_progress", lambda *a, **k: None)
    monkeypatch.setattr(maintenance, "publish_pipeline_event", lambda *a, **k: None)
    monkeypatch.setattr(
        maintenance, "finish_actor_execution", lambda *a, **k: finishes.append((a, k))
    )

    maintenance._reconcile_inbox_documents_impl(command_id=58)

    assert finishes[0][1]["status"] == "skipped"
