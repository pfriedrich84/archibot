from contextlib import contextmanager

import pytest

from app import actor_runner
from app.jobs.commands import CommandRecord


@pytest.fixture(autouse=True)
def _actor_lease_protocol(monkeypatch):
    @contextmanager
    def lease():
        yield object()

    monkeypatch.setattr(actor_runner, "document_actor_lease", lease)
    monkeypatch.setattr(actor_runner, "embedding_mutation_lease", lease)
    monkeypatch.setattr(actor_runner, "embedding_index_ready", lambda connection: True)


def test_build_embedding_index_uses_command_payload_limit(monkeypatch):
    statuses = []
    builds = []

    monkeypatch.setattr(
        actor_runner,
        "load_command",
        lambda command_id: CommandRecord(
            id=command_id,
            type="embedding_index_build",
            status="pending",
            payload={"limit": "12"},
        ),
    )
    monkeypatch.setattr(
        actor_runner,
        "mark_command_status",
        lambda *args: statuses.append(args),
    )
    monkeypatch.setattr(
        actor_runner,
        "_build_initial_embedding_index_impl",
        lambda *, limit=None, command_id=None: builds.append((limit, command_id)),
    )

    actor_runner.run_embedding_index_build_command(66)

    assert builds == [(12, 66)]
    assert statuses == [(66, "running"), (66, "succeeded")]


def test_build_embedding_index_accepts_missing_limit(monkeypatch):
    builds = []

    monkeypatch.setattr(
        actor_runner,
        "load_command",
        lambda command_id: CommandRecord(
            id=command_id,
            type="embedding_index_build",
            status="pending",
            payload={},
        ),
    )
    monkeypatch.setattr(actor_runner, "mark_command_status", lambda *args: None)
    monkeypatch.setattr(
        actor_runner,
        "_build_initial_embedding_index_impl",
        lambda *, limit=None, command_id=None: builds.append((limit, command_id)),
    )

    actor_runner.run_embedding_index_build_command(66)

    assert builds == [(None, 66)]


def test_build_embedding_index_rejects_wrong_command_type(monkeypatch):
    statuses = []

    monkeypatch.setattr(
        actor_runner,
        "load_command",
        lambda command_id: CommandRecord(
            id=command_id,
            type="poll_reconciliation",
            status="pending",
            payload={},
        ),
    )
    monkeypatch.setattr(actor_runner, "mark_command_status", lambda *args: statuses.append(args))

    with pytest.raises(actor_runner.ActorRunnerError, match="expected 'embedding_index_build'"):
        actor_runner.run_embedding_index_build_command(66)

    assert statuses == []


def test_build_embedding_index_marks_failed_and_reraises(monkeypatch):
    statuses = []

    monkeypatch.setattr(
        actor_runner,
        "load_command",
        lambda command_id: CommandRecord(
            id=command_id,
            type="embedding_index_build",
            status="pending",
            payload={"limit": 5},
        ),
    )
    monkeypatch.setattr(actor_runner, "mark_command_status", lambda *args: statuses.append(args))

    def fail(*, limit=None, command_id=None):
        raise RuntimeError("provider down")

    monkeypatch.setattr(actor_runner, "_build_initial_embedding_index_impl", fail)

    with pytest.raises(RuntimeError, match="provider down"):
        actor_runner.run_embedding_index_build_command(66)

    assert statuses[0] == (66, "running")
    assert statuses[1][0:2] == (66, "failed")
    assert statuses[1][2].startswith("actor_failed:RuntimeError: provider down")
    assert "test_actor_runner.py" in statuses[1][2]


def test_run_document_pipeline_revalidates_and_mutates_inside_child_shared_lease(monkeypatch):
    events = []
    connection = object()

    @contextmanager
    def lease():
        events.append("shared-acquired")
        try:
            yield connection
        finally:
            events.append("shared-released")

    monkeypatch.setattr(actor_runner, "document_actor_lease", lease)
    monkeypatch.setattr(
        actor_runner,
        "embedding_index_ready",
        lambda owned_connection: (
            events.append(
                "ready-on-owner" if owned_connection is connection else "wrong-connection"
            )
            or True
        ),
    )
    monkeypatch.setattr(
        actor_runner,
        "_handle_document_pipeline_impl",
        lambda pipeline_run_id, *, embedding_ready: events.append(
            f"mutated:{pipeline_run_id}:ready={embedding_ready}"
        ),
    )

    actor_runner.run_document_pipeline(77)

    assert events == [
        "shared-acquired",
        "ready-on-owner",
        "mutated:77:ready=True",
        "shared-released",
    ]


def test_run_webhook_delivery_invokes_fixed_actor(monkeypatch):
    calls = []

    monkeypatch.setattr(
        actor_runner,
        "_handle_paperless_webhook_impl",
        lambda webhook_delivery_id: calls.append(webhook_delivery_id),
    )

    actor_runner.run_webhook_delivery(78)

    assert calls == [78]


def test_main_build_embedding_index_invokes_command(monkeypatch):
    calls = []

    monkeypatch.setattr(
        actor_runner,
        "run_embedding_index_build_command",
        lambda command_id: calls.append(command_id),
    )

    assert actor_runner.main(["build-embedding-index", "--command-id", "66"]) == 0
    assert calls == [66]


def test_run_poll_reconciliation_uses_command_payload_limit_and_force(monkeypatch):
    statuses = []
    calls = []

    monkeypatch.setattr(
        actor_runner,
        "load_command",
        lambda command_id: CommandRecord(
            id=command_id,
            type="poll_reconciliation",
            status="pending",
            payload={"limit": "3", "force": True},
        ),
    )
    monkeypatch.setattr(actor_runner, "mark_command_status", lambda *args: statuses.append(args))
    monkeypatch.setattr(
        actor_runner,
        "_reconcile_inbox_documents_impl",
        lambda *, limit=None, force=False, command_id=None: calls.append(
            (limit, force, command_id)
        ),
    )

    actor_runner.run_poll_reconciliation_command(44)

    assert calls == [(3, True, 44)]
    assert statuses == [(44, "running"), (44, "succeeded")]


def test_embedding_build_transition_and_reindex_lifecycle_are_inside_child_exclusive_lease(
    monkeypatch,
):
    events = []

    @contextmanager
    def lease():
        events.append("exclusive-acquired")
        try:
            yield object()
        finally:
            events.append("exclusive-released")

    monkeypatch.setattr(actor_runner, "embedding_mutation_lease", lease)
    monkeypatch.setattr(
        actor_runner,
        "load_command",
        lambda command_id: CommandRecord(
            id=command_id,
            type="embedding_index_build",
            status="queued",
            payload={},
        ),
    )
    monkeypatch.setattr(
        actor_runner,
        "mark_command_status",
        lambda command_id, status, *args: events.append(f"command:{status}"),
    )
    monkeypatch.setattr(
        actor_runner,
        "_build_initial_embedding_index_impl",
        lambda **kwargs: events.append("stale-build-complete"),
    )

    actor_runner.run_embedding_index_build_command(45)

    assert events == [
        "exclusive-acquired",
        "command:running",
        "stale-build-complete",
        "command:succeeded",
        "exclusive-released",
    ]


def test_run_reindex_uses_embedding_rebuild_actor(monkeypatch):
    statuses = []
    calls = []

    monkeypatch.setattr(
        actor_runner,
        "load_command",
        lambda command_id: CommandRecord(
            id=command_id,
            type="reindex",
            status="pending",
            payload={},
        ),
    )
    monkeypatch.setattr(actor_runner, "mark_command_status", lambda *args: statuses.append(args))
    monkeypatch.setattr(
        actor_runner,
        "_build_initial_embedding_index_impl",
        lambda *, limit=None, command_id=None: calls.append((limit, command_id)),
    )

    actor_runner.run_reindex_command(45)

    assert calls == [(None, 45)]
    assert statuses == [(45, "running"), (45, "succeeded")]


def test_run_review_commit_uses_command_payload_review_suggestion_id(monkeypatch):
    calls = []

    monkeypatch.setattr(
        actor_runner,
        "load_command",
        lambda command_id: CommandRecord(
            id=command_id,
            type="review_commit",
            status="pending",
            payload={"review_suggestion_id": "88"},
        ),
    )
    monkeypatch.setattr(
        actor_runner,
        "_commit_review_suggestion_impl",
        lambda review_suggestion_id, command_id=None: calls.append(
            (review_suggestion_id, command_id)
        ),
    )

    actor_runner.run_review_commit_command(99)

    assert calls == [(88, 99)]


def test_run_review_commit_rejects_wrong_command_type(monkeypatch):
    monkeypatch.setattr(
        actor_runner,
        "load_command",
        lambda command_id: CommandRecord(
            id=command_id,
            type="embedding_index_build",
            status="pending",
            payload={"review_suggestion_id": "88"},
        ),
    )

    with pytest.raises(actor_runner.ActorRunnerError, match="expected 'review_commit'"):
        actor_runner.run_review_commit_command(99)


def test_run_review_commit_requires_review_suggestion_id(monkeypatch):
    monkeypatch.setattr(
        actor_runner,
        "load_command",
        lambda command_id: CommandRecord(
            id=command_id,
            type="review_commit",
            status="pending",
            payload={},
        ),
    )

    with pytest.raises(actor_runner.ActorRunnerError, match=r"payload\.review_suggestion_id"):
        actor_runner.run_review_commit_command(99)


def test_main_process_document_invokes_pipeline_run(monkeypatch):
    calls = []

    monkeypatch.setattr(
        actor_runner,
        "run_document_pipeline",
        lambda pipeline_run_id: calls.append(pipeline_run_id),
    )

    assert actor_runner.main(["process-document", "--pipeline-run-id", "77"]) == 0
    assert calls == [77]


def test_main_handle_webhook_invokes_delivery(monkeypatch):
    calls = []

    monkeypatch.setattr(
        actor_runner,
        "run_webhook_delivery",
        lambda webhook_delivery_id: calls.append(webhook_delivery_id),
    )

    assert actor_runner.main(["handle-webhook", "--delivery-id", "78"]) == 0
    assert calls == [78]


def test_main_commit_review_invokes_command(monkeypatch):
    calls = []

    monkeypatch.setattr(
        actor_runner, "run_review_commit_command", lambda command_id: calls.append(command_id)
    )

    assert actor_runner.main(["commit-review", "--command-id", "99"]) == 0
    assert calls == [99]


def test_main_reconcile_poll_invokes_command(monkeypatch):
    calls = []

    monkeypatch.setattr(
        actor_runner, "run_poll_reconciliation_command", lambda command_id: calls.append(command_id)
    )

    assert actor_runner.main(["reconcile-poll", "--command-id", "44"]) == 0
    assert calls == [44]


def test_main_reindex_invokes_command(monkeypatch):
    calls = []

    monkeypatch.setattr(
        actor_runner, "run_reindex_command", lambda command_id: calls.append(command_id)
    )

    assert actor_runner.main(["reindex", "--command-id", "45"]) == 0
    assert calls == [45]
