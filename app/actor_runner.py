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

from app.execution_lifecycle import (
    DomainOutcome,
    DomainStatus,
    InvocationFence,
    outcome_for_source,
    protocol_failure,
    reset_invocation_fence,
    set_invocation_fence,
)
from app.jobs.commands import CommandRecord, load_command
from app.jobs.pipeline_fence import (
    document_actor_lease,
    embedding_index_ready,
    embedding_mutation_lease,
)

log = structlog.get_logger(__name__)

EMBEDDING_INDEX_BUILD_COMMAND_TYPE = "embedding_index_build"
POLL_RECONCILIATION_COMMAND_TYPE = "poll_reconciliation"
REINDEX_COMMAND_TYPE = "reindex"
REINDEX_OCR_COMMAND_TYPE = "reindex_ocr"
REVIEW_COMMIT_COMMAND_TYPE = "review_commit"


class ActorRunnerError(RuntimeError):
    """Raised when a fixed actor command cannot be executed safely."""


# Keep actor imports behind the protocol boundary. Optional/legacy integration
# imports must not prevent the fixed runner from emitting a protocol-failure
# record when configuration or database bootstrap fails.
def _build_initial_embedding_index_impl(**kwargs):
    from app.actors.embedding import _build_initial_embedding_index_impl as invoke

    return invoke(**kwargs)


def _reconcile_inbox_documents_impl(**kwargs):
    from app.actors.maintenance import _reconcile_inbox_documents_impl as invoke

    return invoke(**kwargs)


def _reindex_ocr_documents_impl(**kwargs):
    from app.actors.maintenance import _reindex_ocr_documents_impl as invoke

    return invoke(**kwargs)


def _handle_document_pipeline_impl(pipeline_run_id: int, **kwargs):
    from app.actors.document import _handle_document_pipeline_impl as invoke

    return invoke(pipeline_run_id, **kwargs)


def _commit_review_suggestion_impl(review_suggestion_id: int, command_id: int | None = None):
    from app.actors.review import _commit_review_suggestion_impl as invoke

    return invoke(review_suggestion_id, command_id)


def _handle_paperless_webhook_impl(webhook_delivery_id: int):
    from app.actors.webhook import _handle_paperless_webhook_impl as invoke

    return invoke(webhook_delivery_id)


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
        if "/app/actor_runner.py" in frame.filename or frame.filename.endswith(
            "app/actor_runner.py"
        ):
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


def _payload_bool(command: CommandRecord, key: str) -> bool:
    """Return a boolean payload flag from durable command payload values."""
    raw_value = command.payload.get(key)
    if isinstance(raw_value, bool):
        return raw_value
    if raw_value is None or raw_value == "":
        return False
    if isinstance(raw_value, int | float):
        return bool(raw_value)
    if isinstance(raw_value, str):
        return raw_value.strip().lower() in {"1", "true", "yes", "on"}
    return False


def run_embedding_index_build_command(command_id: int) -> None:
    """Run an embedding build while the Python child owns the exclusive lease."""
    command = load_command(command_id)
    if command is None:
        _fail(f"Command {command_id} was not found")
    if command.type != EMBEDDING_INDEX_BUILD_COMMAND_TYPE:
        _fail(
            f"Command {command.id} has type {command.type!r}; "
            f"expected {EMBEDDING_INDEX_BUILD_COMMAND_TYPE!r}"
        )

    limit = _payload_limit(command)
    with embedding_mutation_lease():
        log.info(
            "embedding actor command started",
            command_id=command.id,
            command_type=command.type,
            limit=limit,
        )
        try:
            _build_initial_embedding_index_impl(limit=limit, command_id=command.id)
        except Exception as exc:
            log.warning(
                "embedding actor command failed",
                command_id=command.id,
                command_type=command.type,
                error_type=type(exc).__name__,
                error=str(exc)[:1000],
                exc_info=True,
            )
            raise

        _finalize_command_from_execution(command.id, "build_embedding_index")
        log.info(
            "embedding actor command finished",
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


def _finalize_command_from_execution(command_id: int, actor_name: str) -> DomainOutcome:
    outcome = outcome_for_source(actor_name=actor_name, source_kind="command", source_id=command_id)
    if outcome is None or outcome.status is DomainStatus.PROTOCOL_FAILURE:
        raise ActorRunnerError("Actor execution did not produce a durable domain outcome")
    return outcome


def run_poll_reconciliation_command(command_id: int) -> None:
    """Run polling reconciliation from the durable command payload."""
    command = _load_typed_command(command_id, POLL_RECONCILIATION_COMMAND_TYPE)
    limit = _payload_limit(command)
    force = _payload_bool(command, "force")
    log.info(
        "poll reconciliation actor command started",
        command_id=command.id,
        limit=limit,
        force=force,
    )
    try:
        _reconcile_inbox_documents_impl(limit=limit, force=force, command_id=command.id)
    except Exception:
        raise
    _finalize_command_from_execution(command.id, "reconcile_inbox_documents")
    log.info("poll reconciliation actor command finished", command_id=command.id)


def run_reindex_command(command_id: int) -> None:
    """Run reindex while the Python child owns the exclusive mutation lease."""
    command = _load_typed_command(command_id, REINDEX_COMMAND_TYPE)
    limit = _payload_limit(command)
    with embedding_mutation_lease():
        log.info("reindex actor command started", command_id=command.id, limit=limit)
        try:
            _build_initial_embedding_index_impl(
                limit=limit, command_id=command.id, actor_name="reindex"
            )
        except Exception:
            raise
        _finalize_command_from_execution(command.id, "reindex")
        log.info("reindex actor command finished", command_id=command.id)


def run_reindex_ocr_command(command_id: int) -> None:
    """Run OCR reindex from the durable command payload."""
    command = _load_typed_command(command_id, REINDEX_OCR_COMMAND_TYPE)
    limit = _payload_limit(command)
    force = _payload_bool(command, "force")
    log.info(
        "ocr reindex actor command started",
        command_id=command.id,
        limit=limit,
        force=force,
    )
    try:
        _reindex_ocr_documents_impl(command_id=command.id, limit=limit, force=force)
    except Exception:
        raise
    _finalize_command_from_execution(command.id, "reindex_ocr")
    log.info("ocr reindex actor command finished", command_id=command.id)


def run_document_pipeline(pipeline_run_id: int) -> None:
    """Run one document actor while this Python child owns the shared lease."""
    with document_actor_lease() as lease_connection:
        # This is the decisive readiness read. It uses the exact session that
        # owns the shared lease and is passed into the actor so no unfenced or
        # second-session gate read controls mutation.
        ready = embedding_index_ready(lease_connection)
        log.info(
            "document actor command started",
            pipeline_run_id=pipeline_run_id,
            embedding_index_ready=ready,
        )
        _handle_document_pipeline_impl(pipeline_run_id, embedding_ready=ready)
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

    ocr_reindex = subparsers.add_parser(
        "reindex-ocr",
        help="Run OCR reindex from a durable command id",
    )
    ocr_reindex.add_argument(
        "--command-id",
        type=int,
        required=True,
        help="Durable commands.id for a reindex_ocr command",
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

    for actor_parser in (
        embedding,
        document,
        poll,
        reindex,
        ocr_reindex,
        webhook,
        review,
    ):
        actor_parser.add_argument("--execution-token", required=True)
        actor_parser.add_argument("--source-version", required=True, type=int)
        actor_parser.add_argument("--actor-execution-id", required=True, type=int)
        actor_parser.add_argument("--attempt", required=True, type=int)
    return parser


def _invocation(args: argparse.Namespace):
    """Return callable, durable identity, protocol actor and execution actor."""
    if args.command == "build-embedding-index":
        return (
            run_embedding_index_build_command,
            args.command_id,
            "build_embedding_index",
            "command",
            "build_embedding_index",
        )
    if args.command == "process-document":
        return (
            run_document_pipeline,
            args.pipeline_run_id,
            "handle_document_pipeline",
            "pipeline_run",
            "handle_document_pipeline",
        )
    if args.command == "reconcile-poll":
        return (
            run_poll_reconciliation_command,
            args.command_id,
            "reconcile_inbox_documents",
            "command",
            "reconcile_inbox_documents",
        )
    if args.command == "reindex":
        return (
            run_reindex_command,
            args.command_id,
            "reindex",
            "command",
            "reindex",
        )
    if args.command == "reindex-ocr":
        return (
            run_reindex_ocr_command,
            args.command_id,
            "reindex_ocr",
            "command",
            "reindex_ocr",
        )
    if args.command == "handle-webhook":
        return (
            run_webhook_delivery,
            args.delivery_id,
            "handle_paperless_webhook",
            "webhook_delivery",
            "handle_paperless_webhook",
        )
    if args.command == "commit-review":
        return (
            run_review_commit_command,
            args.command_id,
            "commit_review_suggestion",
            "command",
            "commit_review_suggestion",
        )
    raise ActorRunnerError(f"Unsupported actor command: {args.command}")


def main(argv: Sequence[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    invoke, source_id, actor_name, source_kind, execution_actor_name = _invocation(args)
    fence = InvocationFence(
        actor_name=actor_name,
        execution_actor_name=execution_actor_name,
        source_kind=source_kind,
        source_id=source_id,
        execution_token=args.execution_token,
        source_version=args.source_version,
        actor_execution_id=args.actor_execution_id,
        attempt=args.attempt,
    )
    token = set_invocation_fence(fence)
    failure: BaseException | None = None
    try:
        try:
            invoke(source_id)
        except BaseException as exc:  # always emit one final protocol record
            failure = exc

        try:
            outcome = outcome_for_source(
                actor_name=actor_name,
                source_kind=source_kind,
                source_id=source_id,
                execution_actor_name=execution_actor_name,
                execution_token=args.execution_token,
                source_version=args.source_version,
            )
        except Exception as exc:
            outcome = protocol_failure(
                actor_name=actor_name,
                source_kind=source_kind,
                source_id=source_id,
                error_type=f"outcome_read_failed:{type(exc).__name__}",
            )
        if outcome is None:
            # Never infer success or failure from return/exit. No matching
            # durable final execution is itself a protocol failure.
            outcome = protocol_failure(
                actor_name=actor_name,
                source_kind=source_kind,
                source_id=source_id,
                error_type=(
                    f"execution_missing:{type(failure).__name__}"
                    if failure is not None
                    else "execution_missing"
                ),
            )

        if failure is not None:
            log.warning(
                "actor invocation ended with domain exception",
                actor_name=actor_name,
                source_kind=source_kind,
                source_id=source_id,
                outcome=outcome.status.value,
                error_type=type(failure).__name__,
            )
        # This must be the one final stdout record: Laravel rejects chatter
        # after it and more than one protocol record.
        print(outcome.encode(), flush=True)
        return 1 if failure is not None or outcome.status is DomainStatus.PROTOCOL_FAILURE else 0
    finally:
        reset_invocation_fence(token)


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())
