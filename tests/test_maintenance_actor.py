from types import SimpleNamespace

import pytest

from app.actors import maintenance
from app.jobs.actor_execution import ActorExecutionHandle
from app.jobs.pipeline_start import PipelineStartResult


def test_poll_reconciliation_skips_classified_marker_and_starts_unprocessed(monkeypatch):
    starts = []
    finishes = []
    progresses = []
    events = []

    monkeypatch.setattr(maintenance.settings, "paperless_inbox_tag_id", 123)
    monkeypatch.setattr(
        maintenance,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=7, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )

    async def fake_fetch():
        return [
            SimpleNamespace(id=42, modified="2026-05-08T12:00:00Z"),
            SimpleNamespace(id=43, modified=None),
        ]

    def fake_start(**kwargs):
        starts.append(kwargs)
        return PipelineStartResult(
            status="pending", pipeline_run_id=1, pipeline_dedupe_key="dedupe"
        )

    monkeypatch.setattr(maintenance, "_fetch_inbox_documents", fake_fetch)
    monkeypatch.setattr(maintenance, "classified_document_ids", lambda document_ids: {42})
    monkeypatch.setattr(maintenance, "start_or_attach_document_pipeline", fake_start)
    monkeypatch.setattr(
        maintenance,
        "update_actor_execution_progress",
        lambda *args, **kwargs: progresses.append((args, kwargs)),
    )
    monkeypatch.setattr(
        maintenance, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )
    monkeypatch.setattr(
        maintenance,
        "finish_actor_execution",
        lambda *args, **kwargs: finishes.append((args, kwargs)),
    )

    maintenance._reconcile_inbox_documents_impl(limit=None)

    assert starts == [
        {"trigger_source": "poll", "paperless_document_id": 43, "paperless_modified": None}
    ]
    assert len(progresses) == 4
    assert progresses[0][0][1].phase == "poll_reconciliation_prepare"
    assert progresses[1][0][1].phase == "poll_reconciliation"
    assert progresses[-1][0][1].done == 2
    assert progresses[-1][0][1].skipped == 1
    assert events[0] == (
        ("poll.document.skipped_already_classified",),
        {
            "paperless_document_id": 42,
            "message": "Already classified Inbox Document skipped.",
            "payload": {"marker": "review_suggestion"},
        },
    )
    assert events[-1] == (
        ("poll.reconciliation.completed",),
        {
            "message": "Polling reconciliation completed.",
            "payload": {
                "documents_seen": 2,
                "pipelines_started": 1,
                "pipelines_coalesced": 0,
                "documents_skipped_already_classified": 1,
                "force": False,
            },
        },
    )
    assert finishes[-1][1] == {"status": "succeeded"}


def test_forced_poll_bypasses_marker_and_forces_new_pipeline_run(monkeypatch):
    starts = []
    events = []

    monkeypatch.setattr(maintenance.settings, "paperless_inbox_tag_id", 123)
    monkeypatch.setattr(
        maintenance,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=None, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )

    async def fake_fetch():
        return [SimpleNamespace(id=42, modified="2026-05-08T12:00:00Z")]

    def fake_start(**kwargs):
        starts.append(kwargs)
        return PipelineStartResult(
            status="pending", pipeline_run_id=1, pipeline_dedupe_key="forced"
        )

    monkeypatch.setattr(maintenance, "_fetch_inbox_documents", fake_fetch)
    monkeypatch.setattr(
        maintenance,
        "classified_document_ids",
        lambda document_ids: (_ for _ in ()).throw(
            AssertionError("forced poll must not load classification markers")
        ),
    )
    monkeypatch.setattr(maintenance, "start_or_attach_document_pipeline", fake_start)
    monkeypatch.setattr(
        maintenance, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )
    monkeypatch.setattr(maintenance, "finish_actor_execution", lambda *args, **kwargs: None)

    maintenance._reconcile_inbox_documents_impl(force=True)

    assert starts == [
        {
            "trigger_source": "poll",
            "paperless_document_id": 42,
            "paperless_modified": "2026-05-08T12:00:00Z",
            "reprocess_requested": True,
            "reprocess_reason": "forced_poll_reconciliation",
            "reprocess_mode": "poll_force",
            "force_new_run": True,
        }
    ]
    assert events[-1][1]["payload"] == {
        "documents_seen": 1,
        "pipelines_started": 1,
        "pipelines_coalesced": 0,
        "documents_skipped_already_classified": 0,
        "force": True,
    }


def test_poll_reconciliation_schedules_retry_for_transient_fetch_failure(monkeypatch):
    retries = []
    events = []

    monkeypatch.setattr(maintenance.settings, "paperless_inbox_tag_id", 123)
    monkeypatch.setattr(
        maintenance,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=7, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )

    async def fake_fetch():
        raise TimeoutError("paperless slow")

    monkeypatch.setattr(maintenance, "_fetch_inbox_documents", fake_fetch)
    monkeypatch.setattr(
        maintenance, "update_actor_execution_progress", lambda *args, **kwargs: None
    )
    monkeypatch.setattr(
        maintenance,
        "schedule_actor_execution_retry",
        lambda *args, **kwargs: retries.append((args, kwargs)),
    )
    monkeypatch.setattr(
        maintenance, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )

    with pytest.raises(TimeoutError):
        maintenance._reconcile_inbox_documents_impl(limit=None)

    assert retries[0][1] == {
        "retry_class": "transient_network",
        "retry_reason": "TimeoutError",
        "backoff_seconds": 30,
        "error_message": "paperless slow",
    }
    assert events[0][0] == ("actor.retry_scheduled",)
    assert events[0][1]["payload"]["actor_name"] == "reconcile_inbox_documents"


def test_poll_reconciliation_skips_without_inbox_tag(monkeypatch):
    finishes = []
    events = []

    monkeypatch.setattr(maintenance.settings, "paperless_inbox_tag_id", 0)
    monkeypatch.setattr(
        maintenance,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=7, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )
    monkeypatch.setattr(
        maintenance, "update_actor_execution_progress", lambda *args, **kwargs: None
    )
    monkeypatch.setattr(
        maintenance, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )
    monkeypatch.setattr(
        maintenance,
        "finish_actor_execution",
        lambda *args, **kwargs: finishes.append((args, kwargs)),
    )

    maintenance._reconcile_inbox_documents_impl(limit=None)

    assert events == [
        (
            ("poll.reconciliation.skipped",),
            {
                "level": "warning",
                "message": "Polling reconciliation skipped because PAPERLESS_INBOX_TAG_ID is not configured.",
            },
        )
    ]
    assert finishes[0][1]["status"] == "skipped"
