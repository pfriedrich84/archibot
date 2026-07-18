#!/usr/bin/env python3
"""Test-only deterministic adapter around production actor_runner/lifecycle.

Symfony still launches this as its configured Python binary and supplies
``-m app.actor_runner``. The adapter replaces only productive external work;
claim activation, lifecycle SQL, source/execution finalization, protocol
construction, and actor_runner.main are production code.
"""

from __future__ import annotations

import builtins
import contextlib
import json
import os
import signal
import sys
import time

# Share Laravel's file-backed SQLite fixture with the Python child.
database = os.environ.get("DB_DATABASE")
if database and os.environ.get("DB_CONNECTION") == "sqlite":
    os.environ["DATABASE_URL"] = f"sqlite:///{database}"

from app import actor_runner  # noqa: E402
from app.execution_lifecycle import ExecutionLifecycle, current_invocation_fence  # noqa: E402

scenario = os.environ.get("ARCHIBOT_ACTOR_FIXTURE_SCENARIO", "success")


def deterministic_actor(*args, **kwargs) -> None:
    fence = current_invocation_fence()
    if fence is None:
        raise RuntimeError("deterministic actor requires the production invocation fence")
    source_argument = {
        "pipeline_run": {"pipeline_run_id": fence.source_id},
        "command": {"command_id": fence.source_id},
        "webhook_delivery": {"webhook_delivery_id": fence.source_id},
    }[fence.source_kind]
    lifecycle = ExecutionLifecycle.start(
        actor_name=fence.execution_actor_name,
        queue_name="laravel.database",
        **source_argument,
    )

    if scenario == "timeout":
        time.sleep(5)
    if scenario == "signal":
        os.kill(os.getpid(), signal.SIGTERM)
    if scenario == "crash":
        os._exit(70)
    if scenario == "retrying":
        lifecycle.fail(TimeoutError("deterministic transient failure"))
        return

    status = {
        "skipped": "skipped",
        "blocked": "blocked",
        "cancelled": "cancelled",
        "failed-permanent": "failed_permanent",
    }.get(scenario, "succeeded")
    lifecycle.finish(
        status,
        error_type=None if status == "succeeded" else "deterministic_domain_outcome",
    )


# External actor work and leases are the only replaced seams.
actor_runner._build_initial_embedding_index_impl = deterministic_actor
actor_runner._reconcile_inbox_documents_impl = deterministic_actor
actor_runner._reindex_ocr_documents_impl = deterministic_actor
actor_runner._handle_document_pipeline_impl = deterministic_actor
actor_runner._commit_review_suggestion_impl = deterministic_actor
actor_runner._handle_paperless_webhook_impl = deterministic_actor
actor_runner.document_actor_lease = lambda: contextlib.nullcontext(object())
actor_runner.embedding_mutation_lease = lambda: contextlib.nullcontext(object())
actor_runner.embedding_index_ready = lambda connection: True


def run_entity(command_id: int) -> None:
    deterministic_actor(command_id)


actor_runner.run_sync_entity_approval_command = run_entity

# Process/protocol fault cases happen around the genuine final durable outcome.
original_print = builtins.print


def protocol_print(value="", *args, **kwargs):
    text = str(value)
    try:
        candidate = json.loads(text)
    except (TypeError, ValueError):
        candidate = None
    if not isinstance(candidate, dict) or candidate.get("protocol") != "archibot.actor-outcome":
        return original_print(value, *args, **kwargs)
    if scenario == "missing":
        return None
    if scenario == "malformed":
        return original_print("not-json", *args, **kwargs)
    if scenario == "version-mismatch":
        payload = candidate
        payload["version"] = 999
        return original_print(json.dumps(payload, separators=(",", ":")), *args, **kwargs)
    return original_print(value, *args, **kwargs)


builtins.print = protocol_print
argv = sys.argv[1:]
if argv[:2] == ["-m", "app.actor_runner"]:
    argv = argv[2:]
raise SystemExit(actor_runner.main(argv))
