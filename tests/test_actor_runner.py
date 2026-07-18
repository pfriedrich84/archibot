import json
import os
import subprocess
import sys
from contextlib import contextmanager
from pathlib import Path

import pytest

from app import actor_runner
from app.jobs.commands import CommandRecord

FENCE_ARGS = [
    "--execution-token",
    "test-token",
    "--source-version",
    "1",
    "--actor-execution-id",
    "1",
    "--attempt",
    "1",
]


def fenced(argv):
    return [*argv, *FENCE_ARGS]


@pytest.fixture(autouse=True)
def _actor_lease_protocol(monkeypatch):
    @contextmanager
    def lease():
        yield object()

    monkeypatch.setattr(actor_runner, "document_actor_lease", lease)
    monkeypatch.setattr(actor_runner, "embedding_mutation_lease", lease)
    monkeypatch.setattr(actor_runner, "embedding_index_ready", lambda connection: True)
    monkeypatch.setattr(
        actor_runner,
        "outcome_for_source",
        lambda **identity: actor_runner.DomainOutcome(
            status=actor_runner.DomainStatus.SUCCEEDED,
            actor_name=identity["actor_name"],
            source_kind=identity["source_kind"],
            source_id=identity["source_id"],
        ),
    )


def test_build_embedding_index_uses_command_payload_limit(monkeypatch):
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
        "_build_initial_embedding_index_impl",
        lambda *, limit=None, command_id=None: builds.append((limit, command_id)),
    )

    actor_runner.run_embedding_index_build_command(66)

    assert builds == [(12, 66)]


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
    monkeypatch.setattr(
        actor_runner,
        "_build_initial_embedding_index_impl",
        lambda *, limit=None, command_id=None: builds.append((limit, command_id)),
    )

    actor_runner.run_embedding_index_build_command(66)

    assert builds == [(None, 66)]


def test_build_embedding_index_rejects_wrong_command_type(monkeypatch):
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
    with pytest.raises(actor_runner.ActorRunnerError, match="expected 'embedding_index_build'"):
        actor_runner.run_embedding_index_build_command(66)


def test_build_embedding_index_marks_failed_and_reraises(monkeypatch):
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

    def fail(*, limit=None, command_id=None):
        raise RuntimeError("provider down")

    monkeypatch.setattr(actor_runner, "_build_initial_embedding_index_impl", fail)

    with pytest.raises(RuntimeError, match="provider down"):
        actor_runner.run_embedding_index_build_command(66)

    # Source state belongs atomically to the actor's deep lifecycle.


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


@pytest.mark.parametrize(
    ("argv", "actor", "source_kind"),
    [
        (["build-embedding-index", "--command-id", "1"], "build_embedding_index", "command"),
        (
            ["process-document", "--pipeline-run-id", "1"],
            "handle_document_pipeline",
            "pipeline_run",
        ),
        (["reconcile-poll", "--command-id", "1"], "reconcile_inbox_documents", "command"),
        (["reindex", "--command-id", "1"], "reindex", "command"),
        (["reindex-ocr", "--command-id", "1"], "reindex_ocr", "command"),
        (["handle-webhook", "--delivery-id", "1"], "handle_paperless_webhook", "webhook_delivery"),
        (["commit-review", "--command-id", "1"], "commit_review_suggestion", "command"),
    ],
)
def test_every_actor_family_has_fixed_protocol_identity(argv, actor, source_kind):
    args = actor_runner.build_parser().parse_args(fenced(argv))
    _, source_id, protocol_actor, protocol_source, _ = actor_runner._invocation(args)
    assert (source_id, protocol_actor, protocol_source) == (1, actor, source_kind)


@pytest.mark.parametrize(
    ("argv", "actor", "source_kind"),
    [
        (["build-embedding-index", "--command-id", "1"], "build_embedding_index", "command"),
        (
            ["process-document", "--pipeline-run-id", "1"],
            "handle_document_pipeline",
            "pipeline_run",
        ),
        (["reconcile-poll", "--command-id", "1"], "reconcile_inbox_documents", "command"),
        (["reindex", "--command-id", "1"], "reindex", "command"),
        (["reindex-ocr", "--command-id", "1"], "reindex_ocr", "command"),
        (["handle-webhook", "--delivery-id", "1"], "handle_paperless_webhook", "webhook_delivery"),
        (["commit-review", "--command-id", "1"], "commit_review_suggestion", "command"),
    ],
)
def test_every_actor_family_real_subprocess_emits_protocol_failure_on_bootstrap_failure(
    argv, actor, source_kind
):
    env = os.environ.copy()
    env["DATABASE_URL"] = "postgresql://invalid:invalid@127.0.0.1:1/invalid?connect_timeout=1"
    result = subprocess.run(
        [sys.executable, "-m", "app.actor_runner", *fenced(argv)],
        cwd=Path(__file__).parents[1],
        env=env,
        text=True,
        capture_output=True,
        timeout=10,
        check=False,
    )

    assert result.returncode != 0
    lines = [line for line in result.stdout.splitlines() if line.strip()]
    record = json.loads(lines[-1])
    assert sum('"protocol":"archibot.actor-outcome"' in line for line in lines) == 1
    assert record["protocol"] == "archibot.actor-outcome"
    assert record["status"] == "protocol-failure"
    assert record["actor"] == actor
    assert record["source"] == {"kind": source_kind, "id": 1}


def test_main_build_embedding_index_invokes_command(monkeypatch):
    calls = []

    monkeypatch.setattr(
        actor_runner,
        "run_embedding_index_build_command",
        lambda command_id: calls.append(command_id),
    )

    assert actor_runner.main(fenced(["build-embedding-index", "--command-id", "66"])) == 0
    assert calls == [66]


def test_run_poll_reconciliation_uses_command_payload_limit_and_force(monkeypatch):
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
    monkeypatch.setattr(
        actor_runner,
        "_reconcile_inbox_documents_impl",
        lambda *, limit=None, force=False, command_id=None: calls.append(
            (limit, force, command_id)
        ),
    )

    actor_runner.run_poll_reconciliation_command(44)

    assert calls == [(3, True, 44)]


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
        "_build_initial_embedding_index_impl",
        lambda **kwargs: events.append("stale-build-complete"),
    )

    actor_runner.run_embedding_index_build_command(45)

    assert events == [
        "exclusive-acquired",
        "stale-build-complete",
        "exclusive-released",
    ]


def test_run_reindex_uses_embedding_rebuild_actor(monkeypatch):
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
    monkeypatch.setattr(
        actor_runner,
        "_build_initial_embedding_index_impl",
        lambda *, limit=None, command_id=None, actor_name=None: calls.append((limit, command_id)),
    )

    actor_runner.run_reindex_command(45)

    assert calls == [(None, 45)]


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

    assert actor_runner.main(fenced(["process-document", "--pipeline-run-id", "77"])) == 0
    assert calls == [77]


def test_main_handle_webhook_invokes_delivery(monkeypatch):
    calls = []

    monkeypatch.setattr(
        actor_runner,
        "run_webhook_delivery",
        lambda webhook_delivery_id: calls.append(webhook_delivery_id),
    )

    assert actor_runner.main(fenced(["handle-webhook", "--delivery-id", "78"])) == 0
    assert calls == [78]


def test_main_commit_review_invokes_command(monkeypatch):
    calls = []

    monkeypatch.setattr(
        actor_runner, "run_review_commit_command", lambda command_id: calls.append(command_id)
    )

    assert actor_runner.main(fenced(["commit-review", "--command-id", "99"])) == 0
    assert calls == [99]


def test_main_reconcile_poll_invokes_command(monkeypatch):
    calls = []

    monkeypatch.setattr(
        actor_runner, "run_poll_reconciliation_command", lambda command_id: calls.append(command_id)
    )

    assert actor_runner.main(fenced(["reconcile-poll", "--command-id", "44"])) == 0
    assert calls == [44]


def test_main_reindex_invokes_command(monkeypatch):
    calls = []

    monkeypatch.setattr(
        actor_runner, "run_reindex_command", lambda command_id: calls.append(command_id)
    )

    assert actor_runner.main(fenced(["reindex", "--command-id", "45"])) == 0
    assert calls == [45]
