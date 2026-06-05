"""Fixed Python actor command runner for Laravel queued actor jobs.

Laravel database queues are transport only. Queue payloads should contain durable
row identifiers, and this runner loads the durable command/run state before
calling allowlisted Python actor implementations.
"""

from __future__ import annotations

import argparse
import traceback
from collections.abc import Sequence
from typing import NoReturn

import structlog

from app.actors.document import _handle_document_pipeline_impl
from app.actors.embedding import _build_initial_embedding_index_impl
from app.actors.maintenance import _reconcile_inbox_documents_impl
from app.actors.review import _commit_review_suggestion_impl
from app.actors.webhook import _handle_paperless_webhook_impl
from app.jobs.commands import CommandRecord, load_command, mark_command_status

log = structlog.get_logger(__name__)

EMBEDDING_INDEX_BUILD_COMMAND_TYPE = "embedding_index_build"
POLL_RECONCILIATION_COMMAND_TYPE = "poll_reconciliation"
REINDEX_COMMAND_TYPE = "reindex"
REVIEW_COMMIT_COMMAND_TYPE = "review_commit"


class ActorRunnerError(RuntimeError):
    """Raised when a fixed actor command cannot be executed safely."""


def _fail(message: str) -> NoReturn:
    raise ActorRunnerError(message)


def _exception_summary(exc: BaseException) -> str:
    message = str(exc).strip()
    location = _exception_location(exc)
    if not message:
        summary = f"actor_failed:{type(exc).__name__}"
    else:
        summary = f"actor_failed:{type(exc).__name__}: {message[:700]}"
    if location:
        summary = f"{summary} ({location})"
    return summary[:1000]


def _exception_location(exc: BaseException) -> str | None:
    frames = traceback.extract_tb(exc.__traceback__)
    for frame in reversed(frames):
        if "/app/actor_runner.py" in frame.filename or frame.filename.endswith("app/actor_runner.py"):
            continue
        filename = frame.filename.rsplit("/", 1)[-1]
        return f"{filename}:{frame.lineno} in {frame.name}"
    return None


def _payload_limit(command: CommandRecord) -> int | None:
    """Return the optional positive integer limit from a durable command payload."""
    raw_limit = command.payload.get("limit")
    if raw_limit is None or raw_limit == "":
        return None
    try:
        limit = int(raw_limit)
    except (TypeError, ValueError) as exc:
        raise ActorRunnerError(f"Command {command.id} has invalid payload.limit") from exc
    return limit if limit > 0 else None


def run_embedding_index_build_command(command_id: int) -> None:
    """Run an embedding index build from the durable command payload."""
    command = load_command(command_id)
    if command is None:
        _fail(f"Command {command_id} was not found")
    if command.type != EMBEDDING_INDEX_BUILD_COMMAND_TYPE:
        _fail(
            f"Command {command.id} has type {command.type!r}; "
            f"expected {EMBEDDING_INDEX_BUILD_COMMAND_TYPE!r}"
        )

    limit = _payload_limit(command)
    mark_command_status(command.id, "running")
    log.info(
        "embedding actor command started",
        command_id=command.id,
        command_type=command.type,
        limit=limit,
    )
    try:
        _build_initial_embedding_index_impl(limit=limit)
    except Exception as exc:
        mark_command_status(command.id, "failed", _exception_summary(exc))
        log.warning(
            "embedding actor command failed",
            command_id=command.id,
            command_type=command.type,
            error_type=type(exc).__name__,
            error=str(exc)[:1000],
            exc_info=True,
        )
        raise

    mark_command_status(command.id, "succeeded")
    log.info(
        "embedding actor command succeeded",
        command_id=command.id,
        command_type=command.type,
    )


def _load_typed_command(command_id: int, expected_type: str) -> CommandRecord:
    command = load_command(command_id)
    if command is None:
        _fail(f"Command {command_id} was not found")
    if command.type != expected_type:
        _fail(f"Command {command.id} has type {command.type!r}; expected {expected_type!r}")
    return command


def run_poll_reconciliation_command(command_id: int) -> None:
    """Run polling reconciliation from the durable command payload."""
    command = _load_typed_command(command_id, POLL_RECONCILIATION_COMMAND_TYPE)
    limit = _payload_limit(command)
    mark_command_status(command.id, "running")
    log.info("poll reconciliation actor command started", command_id=command.id, limit=limit)
    try:
        _reconcile_inbox_documents_impl(limit=limit)
    except Exception as exc:
        mark_command_status(command.id, "failed", _exception_summary(exc))
        raise
    mark_command_status(command.id, "succeeded")
    log.info("poll reconciliation actor command succeeded", command_id=command.id)


def run_reindex_command(command_id: int) -> None:
    """Run reindex from the durable command payload using the embedding rebuild actor."""
    command = _load_typed_command(command_id, REINDEX_COMMAND_TYPE)
    limit = _payload_limit(command)
    mark_command_status(command.id, "running")
    log.info("reindex actor command started", command_id=command.id, limit=limit)
    try:
        _build_initial_embedding_index_impl(limit=limit)
    except Exception as exc:
        mark_command_status(command.id, "failed", _exception_summary(exc))
        raise
    mark_command_status(command.id, "succeeded")
    log.info("reindex actor command succeeded", command_id=command.id)


def run_document_pipeline(pipeline_run_id: int) -> None:
    """Run one durable document pipeline run."""
    log.info("document actor command started", pipeline_run_id=pipeline_run_id)
    _handle_document_pipeline_impl(pipeline_run_id)
    log.info("document actor command finished", pipeline_run_id=pipeline_run_id)


def run_webhook_delivery(webhook_delivery_id: int) -> None:
    """Run one durable Paperless webhook delivery."""
    log.info("webhook actor command started", webhook_delivery_id=webhook_delivery_id)
    _handle_paperless_webhook_impl(webhook_delivery_id)
    log.info("webhook actor command finished", webhook_delivery_id=webhook_delivery_id)


def run_review_commit_command(command_id: int) -> None:
    """Run a review commit from durable command payload."""
    command = _load_typed_command(command_id, REVIEW_COMMIT_COMMAND_TYPE)

    raw_review_suggestion_id = command.payload.get("review_suggestion_id")
    try:
        review_suggestion_id = int(raw_review_suggestion_id)
    except (TypeError, ValueError) as exc:
        raise ActorRunnerError(
            f"Command {command.id} has invalid payload.review_suggestion_id"
        ) from exc
    if review_suggestion_id <= 0:
        raise ActorRunnerError(f"Command {command.id} has invalid payload.review_suggestion_id")

    log.info(
        "review commit actor command started",
        command_id=command.id,
        review_suggestion_id=review_suggestion_id,
    )
    _commit_review_suggestion_impl(review_suggestion_id, command.id)
    log.info(
        "review commit actor command finished",
        command_id=command.id,
        review_suggestion_id=review_suggestion_id,
    )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Run fixed ArchiBot Python actor commands")
    subparsers = parser.add_subparsers(dest="command", required=True)

    embedding = subparsers.add_parser(
        "build-embedding-index",
        help="Run an embedding index build from a durable command id",
    )
    embedding.add_argument(
        "--command-id",
        type=int,
        required=True,
        help="Durable commands.id for an embedding_index_build command",
    )

    document = subparsers.add_parser(
        "process-document",
        help="Run a document pipeline actor from a durable pipeline run id",
    )
    document.add_argument(
        "--pipeline-run-id",
        type=int,
        required=True,
        help="Durable pipeline_runs.id for a document pipeline run",
    )

    poll = subparsers.add_parser(
        "reconcile-poll",
        help="Run polling reconciliation from a durable command id",
    )
    poll.add_argument(
        "--command-id",
        type=int,
        required=True,
        help="Durable commands.id for a poll_reconciliation command",
    )

    reindex = subparsers.add_parser(
        "reindex",
        help="Run reindex from a durable command id",
    )
    reindex.add_argument(
        "--command-id",
        type=int,
        required=True,
        help="Durable commands.id for a reindex command",
    )

    webhook = subparsers.add_parser(
        "handle-webhook",
        help="Run a Paperless webhook actor from a durable webhook delivery id",
    )
    webhook.add_argument(
        "--delivery-id",
        type=int,
        required=True,
        help="Durable webhook_deliveries.id for a Paperless webhook delivery",
    )

    review = subparsers.add_parser(
        "commit-review",
        help="Run a review commit actor from a durable command id",
    )
    review.add_argument(
        "--command-id",
        type=int,
        required=True,
        help="Durable commands.id for a review_commit command",
    )

    return parser


def main(argv: Sequence[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    if args.command == "build-embedding-index":
        run_embedding_index_build_command(args.command_id)
        return 0
    if args.command == "process-document":
        run_document_pipeline(args.pipeline_run_id)
        return 0
    if args.command == "reconcile-poll":
        run_poll_reconciliation_command(args.command_id)
        return 0
    if args.command == "reindex":
        run_reindex_command(args.command_id)
        return 0
    if args.command == "handle-webhook":
        run_webhook_delivery(args.delivery_id)
        return 0
    if args.command == "commit-review":
        run_review_commit_command(args.command_id)
        return 0

    raise ActorRunnerError(f"Unsupported actor command: {args.command}")


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())
