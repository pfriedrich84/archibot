from types import SimpleNamespace

import pytest

from app import execution_lifecycle
from app.jobs import recovery


def test_recovery_module_delegates_stale_transition_to_deep_lifecycle(monkeypatch):
    calls = []
    monkeypatch.setattr(
        execution_lifecycle,
        "recover_stale_executions",
        lambda **kwargs: calls.append(kwargs) or 3,
    )

    assert recovery.recover_stale_actor_executions(stale_after_seconds=45, limit=7) == (3, 0)
    assert calls == [{"stale_after_seconds": 45, "limit": 7}]


def test_deep_lifecycle_recovers_stale_attempt_and_emits_canonical_event(monkeypatch):
    scheduled = []
    events = []
    stale = SimpleNamespace(
        id=9,
        pipeline_run_id=4,
        command_id=None,
        webhook_delivery_id=None,
        actor_name="handle_document_pipeline",
        attempt=2,
        execution_token="stale-token",
        source_version=7,
    )
    monkeypatch.setattr(
        execution_lifecycle.execution_store,
        "list_stale_running_actor_executions",
        lambda **kwargs: [stale],
    )
    monkeypatch.setattr(
        execution_lifecycle.execution_store,
        "schedule_actor_execution_retry",
        lambda handle, **kwargs: scheduled.append((handle, kwargs)),
    )
    monkeypatch.setattr(
        "app.events.publish.publish_pipeline_event",
        lambda *args, **kwargs: events.append((args, kwargs)),
    )

    assert execution_lifecycle.recover_stale_executions(limit=10) == 1
    assert len(scheduled) == 1
    handle, retry = scheduled[0]
    assert (handle.id, handle.attempt, handle.execution_token, handle.source_version) == (
        9,
        2,
        "stale-token",
        7,
    )
    assert (handle.source_kind, handle.source_id) == ("pipeline_run", 4)
    assert retry == {
        "retry_class": "worker_recovery_stale_actor",
        "retry_reason": "worker_recovery_stale_actor",
        "backoff_seconds": 0,
        "error_message": "Actor execution was left running and recovered after worker restart.",
    }
    assert events == [
        (
            ("actor.recovered_stale",),
            {
                "pipeline_run_id": 4,
                "level": "warning",
                "message": "Stale actor execution recovered after worker restart.",
                "payload": {
                    "actor_execution_id": 9,
                    "actor_name": "handle_document_pipeline",
                    "retry_mode": "recovery",
                },
            },
        )
    ]


def test_recovery_scan_is_transition_only(monkeypatch):
    calls = []
    monkeypatch.setattr(
        execution_lifecycle,
        "run_recovery_transition_scan",
        lambda **kwargs: calls.append(kwargs) or (2, 1),
    )

    recovery.run_recovery_scan(limit=6)

    assert calls == [{"limit": 6}]


@pytest.mark.parametrize(
    ("function", "args"),
    [
        (recovery.enqueue_embedding_build_command, (1,)),
        (recovery.enqueue_poll_reconciliation_command, (1,)),
        (recovery.enqueue_reindex_command, (1,)),
        (recovery.enqueue_ocr_reindex_command, (1,)),
        (recovery.enqueue_review_commit, (1,)),
        (recovery.enqueue_review_commit_command, (1, 2)),
        (recovery.enqueue_document_pipeline_run, (1,)),
        (recovery.enqueue_webhook_delivery, (1,)),
    ],
)
def test_every_python_productive_recovery_dispatch_is_retired(function, args):
    with pytest.raises(RuntimeError, match="Laravel database queues own"):
        function(*args)


def test_release_helpers_do_not_mutate_or_dispatch():
    assert recovery.release_embedding_blocked_runs() == 0
    assert recovery.release_embedding_blocked_webhooks() == 0
