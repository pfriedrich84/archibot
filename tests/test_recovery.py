import pytest

from app.jobs import recovery
from app.jobs.actor_execution import StaleActorExecutionRecord
from app.jobs.commands import CommandRecord


@pytest.fixture(autouse=True)
def default_no_pending_review_commit_commands(monkeypatch):
    monkeypatch.setattr(recovery, "list_pending_review_commit_commands", lambda limit: [])


def test_recover_stale_actor_executions_marks_and_requeues_document_runs(monkeypatch):
    marked = []
    enqueued = []
    events = []

    monkeypatch.setattr(
        recovery,
        "list_stale_running_actor_executions",
        lambda **kwargs: [
            StaleActorExecutionRecord(
                id=7,
                pipeline_run_id=21,
                paperless_document_id=42,
                actor_name="handle_document_pipeline",
                attempt=1,
                max_attempts=5,
            )
        ],
    )
    monkeypatch.setattr(
        recovery,
        "mark_stale_actor_execution_recovered",
        lambda execution_id: marked.append(execution_id),
    )
    monkeypatch.setattr(
        recovery,
        "enqueue_document_pipeline_run",
        lambda pipeline_run_id: enqueued.append(pipeline_run_id),
    )
    monkeypatch.setattr(
        recovery,
        "publish_pipeline_event",
        lambda *args, **kwargs: events.append((args, kwargs)),
    )

    assert recovery.recover_stale_actor_executions(limit=10) == (1, 1)
    assert marked == [7]
    assert enqueued == [21]
    assert events[0][1]["pipeline_run_id"] == 21
    assert events[0][1]["payload"]["actor_execution_id"] == 7


def test_recover_stale_actor_executions_marks_non_document_actor_without_requeue(monkeypatch):
    marked = []
    enqueued = []

    monkeypatch.setattr(
        recovery,
        "list_stale_running_actor_executions",
        lambda **kwargs: [
            StaleActorExecutionRecord(
                id=8,
                pipeline_run_id=None,
                paperless_document_id=42,
                actor_name="handle_paperless_webhook",
                attempt=1,
                max_attempts=5,
            )
        ],
    )
    monkeypatch.setattr(
        recovery,
        "mark_stale_actor_execution_recovered",
        lambda execution_id: marked.append(execution_id),
    )
    monkeypatch.setattr(
        recovery,
        "enqueue_document_pipeline_run",
        lambda pipeline_run_id: enqueued.append(pipeline_run_id),
    )
    monkeypatch.setattr(recovery, "publish_pipeline_event", lambda *args, **kwargs: None)

    assert recovery.recover_stale_actor_executions(limit=10) == (1, 0)
    assert marked == [8]
    assert enqueued == []


def test_recovery_scan_enqueues_queued_webhook_deliveries(monkeypatch):
    enqueued = []

    monkeypatch.setattr(recovery, "recover_stale_actor_executions", lambda limit: (0, 0))
    monkeypatch.setattr(recovery, "finalize_cancel_requested_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "release_embedding_blocked_webhooks", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_queued_webhook_delivery_ids", lambda limit: [3, 5])
    monkeypatch.setattr(
        recovery, "enqueue_webhook_delivery", lambda webhook_id: enqueued.append(webhook_id)
    )
    monkeypatch.setattr(recovery, "release_embedding_blocked_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_pending_document_pipeline_run_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "list_due_retrying_document_pipeline_run_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_embedding_build_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_poll_reconciliation_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_reindex_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_review_commit_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_review_suggestions_ready_to_commit", lambda limit: [])

    recovery.run_recovery_scan(limit=10)

    assert enqueued == [3, 5]


def test_finalize_cancel_requested_runs_marks_runs_cancelled(monkeypatch):
    marked = []
    events = []

    monkeypatch.setattr(recovery, "list_cancel_requested_pipeline_run_ids", lambda limit: [10, 11])
    monkeypatch.setattr(
        recovery, "mark_pipeline_run_cancelled", lambda run_id: marked.append(run_id)
    )
    monkeypatch.setattr(
        recovery,
        "publish_pipeline_event",
        lambda *args, **kwargs: events.append((args, kwargs)),
    )

    assert recovery.finalize_cancel_requested_runs(limit=10) == 2
    assert marked == [10, 11]
    assert [event[1]["pipeline_run_id"] for event in events] == [10, 11]


def test_release_embedding_blocked_runs_does_nothing_until_index_is_ready(monkeypatch):
    monkeypatch.setattr(recovery, "ensure_embedding_index_ready", lambda: False)

    assert recovery.release_embedding_blocked_runs(limit=10) == 0


def test_release_embedding_blocked_runs_marks_runs_pending(monkeypatch):
    marked = []
    events = []

    monkeypatch.setattr(recovery, "ensure_embedding_index_ready", lambda: True)
    monkeypatch.setattr(recovery, "list_embedding_blocked_pipeline_run_ids", lambda limit: [8, 9])
    monkeypatch.setattr(recovery, "mark_pipeline_run_pending", lambda run_id: marked.append(run_id))
    monkeypatch.setattr(
        recovery,
        "publish_pipeline_event",
        lambda *args, **kwargs: events.append((args, kwargs)),
    )

    assert recovery.release_embedding_blocked_runs(limit=10) == 2
    assert marked == [8, 9]
    assert [event[1]["pipeline_run_id"] for event in events] == [8, 9]


def test_release_embedding_blocked_webhooks_marks_deliveries_queued(monkeypatch):
    marked = []
    events = []

    monkeypatch.setattr(recovery, "ensure_embedding_index_ready", lambda: True)
    monkeypatch.setattr(
        recovery, "list_embedding_blocked_webhook_delivery_ids", lambda limit: [3, 4]
    )
    monkeypatch.setattr(
        recovery,
        "mark_webhook_delivery_status",
        lambda delivery_id, status, error: marked.append((delivery_id, status, error)),
    )
    monkeypatch.setattr(
        recovery,
        "publish_pipeline_event",
        lambda *args, **kwargs: events.append((args, kwargs)),
    )

    assert recovery.release_embedding_blocked_webhooks(limit=10) == 2
    assert marked == [(3, "queued", None), (4, "queued", None)]
    assert [event[1]["webhook_delivery_id"] for event in events] == [3, 4]


def test_recovery_scan_enqueues_pending_document_runs(monkeypatch):
    enqueued = []

    monkeypatch.setattr(recovery, "recover_stale_actor_executions", lambda limit: (0, 0))
    monkeypatch.setattr(recovery, "finalize_cancel_requested_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "release_embedding_blocked_webhooks", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_queued_webhook_delivery_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "release_embedding_blocked_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_pending_document_pipeline_run_ids", lambda limit: [21, 22])
    monkeypatch.setattr(recovery, "list_due_retrying_document_pipeline_run_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_embedding_build_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_poll_reconciliation_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_reindex_commands", lambda limit: [])
    monkeypatch.setattr(
        recovery, "enqueue_document_pipeline_run", lambda run_id: enqueued.append(run_id)
    )
    monkeypatch.setattr(recovery, "list_review_suggestions_ready_to_commit", lambda limit: [])

    recovery.run_recovery_scan(limit=10)

    assert enqueued == [21, 22]


def test_recovery_scan_enqueues_due_retrying_document_runs(monkeypatch):
    enqueued = []

    monkeypatch.setattr(recovery, "recover_stale_actor_executions", lambda limit: (0, 0))
    monkeypatch.setattr(recovery, "finalize_cancel_requested_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "release_embedding_blocked_webhooks", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_queued_webhook_delivery_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "release_embedding_blocked_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_pending_document_pipeline_run_ids", lambda limit: [])
    monkeypatch.setattr(
        recovery, "list_due_retrying_document_pipeline_run_ids", lambda limit: [51, 52]
    )
    monkeypatch.setattr(recovery, "list_pending_embedding_build_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_poll_reconciliation_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_reindex_commands", lambda limit: [])
    monkeypatch.setattr(
        recovery, "enqueue_document_pipeline_run", lambda run_id: enqueued.append(run_id)
    )
    monkeypatch.setattr(recovery, "list_review_suggestions_ready_to_commit", lambda limit: [])

    recovery.run_recovery_scan(limit=10)

    assert enqueued == [51, 52]


def test_recovery_scan_enqueues_embedding_build_commands(monkeypatch):
    enqueued = []

    monkeypatch.setattr(recovery, "recover_stale_actor_executions", lambda limit: (0, 0))
    monkeypatch.setattr(recovery, "finalize_cancel_requested_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "release_embedding_blocked_webhooks", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_queued_webhook_delivery_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "release_embedding_blocked_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_pending_document_pipeline_run_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "list_due_retrying_document_pipeline_run_ids", lambda limit: [])
    monkeypatch.setattr(
        recovery,
        "list_pending_embedding_build_commands",
        lambda limit: [
            CommandRecord(
                id=66,
                type="embedding_index_build",
                status="pending",
                payload={"limit": 10},
            )
        ],
    )
    monkeypatch.setattr(
        recovery,
        "enqueue_embedding_build_command",
        lambda command_id, limit: enqueued.append((command_id, limit)),
    )
    monkeypatch.setattr(recovery, "list_pending_poll_reconciliation_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_reindex_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_review_commit_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_review_suggestions_ready_to_commit", lambda limit: [])

    recovery.run_recovery_scan(limit=10)

    assert enqueued == [(66, 10)]


def test_enqueue_embedding_build_command_marks_queued_and_sends(monkeypatch):
    statuses = []
    sent = []

    class Actor:
        @staticmethod
        def send(limit):
            sent.append(limit)

    monkeypatch.setattr(recovery, "build_initial_embedding_index", Actor())
    monkeypatch.setattr(recovery, "mark_command_status", lambda *args: statuses.append(args))

    recovery.enqueue_embedding_build_command(66, limit=10)

    assert statuses == [(66, "queued")]
    assert sent == [10]


def test_enqueue_command_restores_pending_when_send_fails(monkeypatch):
    statuses = []

    class Actor:
        @staticmethod
        def send(limit):
            raise RuntimeError("broker down")

    monkeypatch.setattr(recovery, "build_initial_embedding_index", Actor())
    monkeypatch.setattr(recovery, "mark_command_status", lambda *args: statuses.append(args))

    try:
        recovery.enqueue_embedding_build_command(66, limit=10)
    except RuntimeError:
        pass
    else:  # pragma: no cover - defensive assertion
        raise AssertionError("send failure did not propagate")

    assert statuses == [(66, "queued"), (66, "pending", "enqueue_failed:RuntimeError")]


def test_recovery_scan_enqueues_poll_reconciliation_commands(monkeypatch):
    enqueued = []

    monkeypatch.setattr(recovery, "recover_stale_actor_executions", lambda limit: (0, 0))
    monkeypatch.setattr(recovery, "finalize_cancel_requested_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "release_embedding_blocked_webhooks", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_queued_webhook_delivery_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "release_embedding_blocked_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_pending_document_pipeline_run_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "list_due_retrying_document_pipeline_run_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_embedding_build_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_poll_reconciliation_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_reindex_commands", lambda limit: [])
    monkeypatch.setattr(
        recovery,
        "list_pending_poll_reconciliation_commands",
        lambda limit: [
            CommandRecord(
                id=77,
                type="poll_reconciliation",
                status="pending",
                payload={"limit": 25},
            )
        ],
    )
    monkeypatch.setattr(
        recovery,
        "enqueue_poll_reconciliation_command",
        lambda command_id, limit: enqueued.append((command_id, limit)),
    )
    monkeypatch.setattr(recovery, "list_review_suggestions_ready_to_commit", lambda limit: [])

    recovery.run_recovery_scan(limit=10)

    assert enqueued == [(77, 25)]


def test_enqueue_poll_reconciliation_command_marks_queued_and_sends(monkeypatch):
    statuses = []
    sent = []

    class Actor:
        @staticmethod
        def send(limit):
            sent.append(limit)

    monkeypatch.setattr(recovery, "reconcile_inbox_documents", Actor())
    monkeypatch.setattr(recovery, "mark_command_status", lambda *args: statuses.append(args))

    recovery.enqueue_poll_reconciliation_command(77, limit=25)

    assert statuses == [(77, "queued")]
    assert sent == [25]


def test_recovery_scan_enqueues_reindex_commands(monkeypatch):
    enqueued = []

    monkeypatch.setattr(recovery, "recover_stale_actor_executions", lambda limit: (0, 0))
    monkeypatch.setattr(recovery, "finalize_cancel_requested_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "release_embedding_blocked_webhooks", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_queued_webhook_delivery_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "release_embedding_blocked_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_pending_document_pipeline_run_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "list_due_retrying_document_pipeline_run_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_embedding_build_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_poll_reconciliation_commands", lambda limit: [])
    monkeypatch.setattr(
        recovery,
        "list_pending_reindex_commands",
        lambda limit: [
            CommandRecord(
                id=88,
                type="reindex",
                status="pending",
                payload={"limit": 50},
            )
        ],
    )
    monkeypatch.setattr(
        recovery,
        "enqueue_reindex_command",
        lambda command_id, limit: enqueued.append((command_id, limit)),
    )
    monkeypatch.setattr(recovery, "list_review_suggestions_ready_to_commit", lambda limit: [])

    recovery.run_recovery_scan(limit=10)

    assert enqueued == [(88, 50)]


def test_enqueue_reindex_command_marks_queued_and_sends_embedding_actor(monkeypatch):
    statuses = []
    sent = []

    class Actor:
        @staticmethod
        def send(limit):
            sent.append(limit)

    monkeypatch.setattr(recovery, "build_initial_embedding_index", Actor())
    monkeypatch.setattr(recovery, "mark_command_status", lambda *args: statuses.append(args))

    recovery.enqueue_reindex_command(88, limit=50)

    assert statuses == [(88, "queued")]
    assert sent == [50]


def test_enqueue_document_pipeline_run_marks_queued_and_sends(monkeypatch):
    statuses = []
    sent = []

    class Actor:
        @staticmethod
        def send(pipeline_run_id):
            sent.append(pipeline_run_id)

    monkeypatch.setattr(recovery, "handle_document_pipeline", Actor())
    monkeypatch.setattr(
        recovery,
        "mark_pipeline_run_status",
        lambda *args, **kwargs: statuses.append((args, kwargs)),
    )

    recovery.enqueue_document_pipeline_run(31)

    assert sent == [31]
    assert statuses == [
        (
            (31,),
            {"status": "queued", "phase": "document_actor", "message": "Document actor queued."},
        )
    ]


def test_enqueue_document_pipeline_run_restores_pending_when_send_fails(monkeypatch):
    statuses = []

    class Actor:
        @staticmethod
        def send(pipeline_run_id):
            raise RuntimeError("broker down")

    monkeypatch.setattr(recovery, "handle_document_pipeline", Actor())
    monkeypatch.setattr(
        recovery,
        "mark_pipeline_run_status",
        lambda *args, **kwargs: statuses.append((args, kwargs)),
    )

    try:
        recovery.enqueue_document_pipeline_run(31)
    except RuntimeError:
        pass
    else:  # pragma: no cover - defensive assertion
        raise AssertionError("send failure did not propagate")

    assert statuses == [
        (
            (31,),
            {"status": "queued", "phase": "document_actor", "message": "Document actor queued."},
        ),
        (
            (31,),
            {
                "status": "pending",
                "phase": "queued",
                "message": "Document actor enqueue failed; recovery will retry.",
                "error_type": "enqueue_failed",
                "error": "RuntimeError",
            },
        ),
    ]


def test_recovery_scan_enqueues_review_commits(monkeypatch):
    enqueued = []

    monkeypatch.setattr(recovery, "recover_stale_actor_executions", lambda limit: (0, 0))
    monkeypatch.setattr(recovery, "finalize_cancel_requested_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "release_embedding_blocked_webhooks", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_queued_webhook_delivery_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "release_embedding_blocked_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_pending_document_pipeline_run_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "list_due_retrying_document_pipeline_run_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_embedding_build_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_poll_reconciliation_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_reindex_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_review_suggestions_ready_to_commit", lambda limit: [44])
    monkeypatch.setattr(
        recovery, "enqueue_review_commit", lambda review_id: enqueued.append(review_id)
    )

    recovery.run_recovery_scan(limit=10)

    assert enqueued == [44]


def test_enqueue_webhook_delivery_uses_dramatiq_send_when_available(monkeypatch):
    sent = []

    class Actor:
        @staticmethod
        def send(webhook_delivery_id):
            sent.append(webhook_delivery_id)

    monkeypatch.setattr(recovery, "handle_paperless_webhook", Actor())

    recovery.enqueue_webhook_delivery(11)

    assert sent == [11]


def test_enqueue_webhook_delivery_calls_plain_function_without_dramatiq(monkeypatch):
    called = []

    monkeypatch.setattr(
        recovery,
        "handle_paperless_webhook",
        lambda webhook_delivery_id: called.append(webhook_delivery_id),
    )

    recovery.enqueue_webhook_delivery(13)

    assert called == [13]


def test_recovery_scan_enqueues_review_commit_commands(monkeypatch):
    enqueued = []

    monkeypatch.setattr(recovery, "recover_stale_actor_executions", lambda limit: (0, 0))
    monkeypatch.setattr(recovery, "finalize_cancel_requested_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_queued_webhook_delivery_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "release_embedding_blocked_runs", lambda limit: 0)
    monkeypatch.setattr(recovery, "list_pending_document_pipeline_run_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "list_due_retrying_document_pipeline_run_ids", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_embedding_build_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_poll_reconciliation_commands", lambda limit: [])
    monkeypatch.setattr(recovery, "list_pending_reindex_commands", lambda limit: [])
    monkeypatch.setattr(
        recovery,
        "list_pending_review_commit_commands",
        lambda limit: [
            CommandRecord(
                id=77,
                type="review_commit",
                status="pending",
                payload={"review_suggestion_id": 44},
            )
        ],
    )
    monkeypatch.setattr(
        recovery,
        "enqueue_review_commit_command",
        lambda command_id, review_id: enqueued.append((command_id, review_id)),
    )
    monkeypatch.setattr(recovery, "list_review_suggestions_ready_to_commit", lambda limit: [])

    recovery.run_recovery_scan(limit=10)

    assert enqueued == [(77, 44)]


def test_enqueue_review_commit_command_restores_pending_when_enqueue_fails(monkeypatch):
    statuses = []

    monkeypatch.setattr(recovery, "mark_command_status", lambda *args: statuses.append(args))
    monkeypatch.setattr(
        recovery,
        "enqueue_review_commit",
        lambda review_id, command_id=None: (_ for _ in ()).throw(RuntimeError("broker down")),
    )

    with pytest.raises(RuntimeError):
        recovery.enqueue_review_commit_command(77, 44)

    assert statuses == [
        (77, "queued"),
        (77, "pending", "enqueue_failed:RuntimeError"),
    ]
