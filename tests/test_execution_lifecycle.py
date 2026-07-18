import json

import pytest

from app import execution_lifecycle as lifecycle


class Result:
    def __init__(self, row):
        self.row = row

    def mappings(self):
        return self

    def first(self):
        return self.row


class Connection:
    def __init__(self, row):
        self.row = row
        self.calls = []

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return None

    def execute(self, statement, params):
        self.calls.append((str(statement), params))
        return Result(self.row)


class Engine:
    def __init__(self, row):
        self.connection = Connection(row)

    def connect(self):
        return self.connection


@pytest.mark.parametrize(
    ("current", "targets"),
    [(state, allowed) for state, allowed in lifecycle.TRANSITION_MATRIX.items()],
)
def test_transition_matrix_is_explicit_and_self_idempotent(current, targets):
    required = {
        "pending",
        "queued",
        "running",
        "blocked",
        "retrying",
        "succeeded",
        "skipped",
        "failed",
        "failed_permanent",
        "cancelled",
    }
    assert set(lifecycle.TRANSITION_MATRIX) == required
    assert current in targets
    for target in required:
        assert lifecycle.transition_allowed(current, target) is (target in targets)


def test_transition_matrix_matches_the_complete_durable_contract():
    expected = {
        "pending": frozenset(
            {"pending", "queued", "running", "retrying", "cancelled", "failed_permanent", "skipped"}
        ),
        "queued": frozenset(
            {"queued", "running", "retrying", "cancelled", "failed_permanent", "skipped"}
        ),
        "running": frozenset(
            {
                "running",
                "succeeded",
                "skipped",
                "blocked",
                "retrying",
                "failed_permanent",
                "cancelled",
            }
        ),
        "retrying": frozenset({"retrying", "failed", "failed_permanent", "cancelled", "skipped"}),
        "succeeded": frozenset({"succeeded"}),
        "skipped": frozenset({"skipped"}),
        "blocked": frozenset({"blocked"}),
        "failed": frozenset({"failed"}),
        "failed_permanent": frozenset({"failed_permanent"}),
        "cancelled": frozenset({"cancelled"}),
    }
    assert expected == lifecycle.TRANSITION_MATRIX


@pytest.mark.parametrize(
    ("stored", "protocol"),
    [
        ("succeeded", "succeeded"),
        ("blocked", "blocked"),
        ("cancelled", "cancelled"),
        ("retrying", "retrying"),
        ("failed_permanent", "failed-permanent"),
    ],
)
def test_restart_reconstructs_versioned_outcome(monkeypatch, stored, protocol):
    fake = Engine(
        {
            "id": 91,
            "status": stored,
            "attempt": 3,
            "next_retry_at": "2026-07-18 10:00:00" if stored == "retrying" else None,
            "error_type": (None if stored == "succeeded" else "transient_network"),
        }
    )
    monkeypatch.setattr(lifecycle, "engine", lambda: fake)

    outcome = lifecycle.outcome_for_source(
        actor_name="handle_document_pipeline",
        source_kind="pipeline_run",
        source_id=77,
    )

    assert outcome is not None
    payload = json.loads(outcome.encode())
    assert payload["protocol"] == "archibot.actor-outcome"
    assert payload["version"] == 1
    assert payload["status"] == protocol
    assert payload["source"] == {"kind": "pipeline_run", "id": 77}
    assert payload["attempt"] == 3
    assert "pipeline_run_id = :source_id" in fake.connection.calls[0][0]


def test_crash_restart_reconstructs_item_progress(monkeypatch):
    pipeline_updates = []
    actor_updates = []
    monkeypatch.setattr(
        "app.jobs.pipeline_items.progress_from_pipeline_items", lambda run_id: (8, 5, 1, 2)
    )
    monkeypatch.setattr(
        "app.jobs.progress.update_pipeline_run_progress",
        lambda run_id, snapshot: pipeline_updates.append((run_id, snapshot)),
    )
    monkeypatch.setattr(
        lifecycle,
        "update_actor_execution_progress",
        lambda execution_id, snapshot, current_item=None: actor_updates.append(
            (execution_id, snapshot, current_item)
        ),
    )

    snapshot = lifecycle.update_item_derived_progress(
        pipeline_run_id=77,
        actor_execution_id=91,
        phase="classifying",
        message="Resumed after crash.",
        current_item="document:42",
    )

    assert (snapshot.total, snapshot.done, snapshot.failed, snapshot.skipped) == (8, 5, 1, 2)
    assert pipeline_updates[0][0] == 77
    assert actor_updates == [(91, snapshot, "document:42")]


def test_lifecycle_schedules_bounded_retry_once(monkeypatch):
    calls = []
    handle = lifecycle.ActorExecutionHandle(
        id=91, actor_name="actor", started_monotonic=0, attempt=2
    )
    monkeypatch.setattr(
        lifecycle,
        "classify_exception",
        lambda exc: lifecycle.RetryClass.TRANSIENT_NETWORK,
    )
    monkeypatch.setattr(lifecycle, "retry_backoff_seconds", lambda attempt: 30)
    monkeypatch.setattr(
        lifecycle.execution_store,
        "schedule_actor_execution_retry",
        lambda *args, **kwargs: calls.append((args, kwargs)),
    )

    decision = lifecycle.ExecutionLifecycle(handle).schedule_retry(TimeoutError("private\npayload"))

    assert decision == (lifecycle.RetryClass.TRANSIENT_NETWORK, 30)
    assert len(calls) == 1
    assert calls[0][1]["error_message"] == "private payload"


def test_fenced_lifecycle_owns_sanitized_retry_event(monkeypatch):
    events = []
    handle = lifecycle.ActorExecutionHandle(
        id=91,
        actor_name="actor",
        started_monotonic=0,
        attempt=2,
        execution_token="token",
        source_kind="command",
        source_id=5,
        source_version=3,
    )
    monkeypatch.setattr(
        lifecycle.execution_store, "schedule_actor_execution_retry", lambda *args, **kwargs: None
    )
    monkeypatch.setattr(
        lifecycle, "classify_exception", lambda exc: lifecycle.RetryClass.TRANSIENT_NETWORK
    )
    monkeypatch.setattr(lifecycle, "retry_backoff_seconds", lambda attempt: 30)
    monkeypatch.setattr(
        "app.events.publish.publish_pipeline_event",
        lambda *args, **kwargs: events.append((args, kwargs)),
    )

    lifecycle.ExecutionLifecycle(handle).fail(TimeoutError("private\npayload"))

    assert events == [
        (
            ("actor.retry_scheduled",),
            {
                "command_id": 5,
                "level": "warning",
                "message": "Actor retry scheduled for Laravel recovery.",
                "payload": {
                    "actor_execution_id": 91,
                    "actor_name": "actor",
                    "retry_class": "transient_network",
                    "retry_reason": "TimeoutError",
                    "backoff_seconds": 30,
                },
            },
        )
    ]


def test_outcome_sanitizes_multiline_error_metadata():
    outcome = lifecycle.DomainOutcome(
        status=lifecycle.DomainStatus.PROTOCOL_FAILURE,
        actor_name="actor",
        source_kind="command",
        source_id=5,
        error_type="bad\nprotocol",
    )

    payload = json.loads(outcome.encode())
    assert payload["status"] == "protocol-failure"
    assert payload["error_type"] == "bad protocol"
