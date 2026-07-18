"""Fenced durable actor lifecycle and strict Laravel outcome protocol."""

from __future__ import annotations

import json
import time
from contextvars import ContextVar, Token
from dataclasses import dataclass
from datetime import datetime
from enum import StrEnum
from typing import Any

from app.jobs import actor_execution as execution_store
from app.jobs.actor_execution import ActorExecutionHandle
from app.jobs.database import engine
from app.jobs.progress import ProgressSnapshot
from app.jobs.retry import RetryClass, classify_exception, retry_backoff_seconds, should_retry

PROTOCOL_NAME = "archibot.actor-outcome"
PROTOCOL_VERSION = 1
MAX_ERROR_LENGTH = 1000


class DomainStatus(StrEnum):
    SUCCEEDED = "succeeded"
    SKIPPED = "skipped"
    BLOCKED = "blocked"
    CANCELLED = "cancelled"
    RETRYING = "retrying"
    FAILED_PERMANENT = "failed-permanent"
    PROTOCOL_FAILURE = "protocol-failure"


TRANSITION_MATRIX: dict[str, frozenset[str]] = {
    "pending": frozenset(
        {"pending", "queued", "running", "retrying", "cancelled", "failed_permanent", "skipped"}
    ),
    "queued": frozenset(
        {"queued", "running", "retrying", "cancelled", "failed_permanent", "skipped"}
    ),
    "running": frozenset(
        {"running", "succeeded", "skipped", "blocked", "retrying", "failed_permanent", "cancelled"}
    ),
    "retrying": frozenset({"retrying", "failed", "failed_permanent", "cancelled", "skipped"}),
    "succeeded": frozenset({"succeeded"}),
    "skipped": frozenset({"skipped"}),
    "blocked": frozenset({"blocked"}),
    "failed": frozenset({"failed"}),
    "failed_permanent": frozenset({"failed_permanent"}),
    "cancelled": frozenset({"cancelled"}),
}


@dataclass(frozen=True)
class FailureDisposition:
    retrying: bool
    retry_class: RetryClass
    backoff_seconds: int | None = None


@dataclass(frozen=True)
class InvocationFence:
    actor_name: str
    execution_actor_name: str
    source_kind: str
    source_id: int
    execution_token: str
    source_version: int
    actor_execution_id: int
    attempt: int


_invocation: ContextVar[InvocationFence | None] = ContextVar("actor_invocation", default=None)


def set_invocation_fence(fence: InvocationFence) -> Token:
    return _invocation.set(fence)


def reset_invocation_fence(token: Token) -> None:
    _invocation.reset(token)


def current_invocation_fence() -> InvocationFence | None:
    return _invocation.get()


def source_fence(source_kind: str, source_id: int) -> tuple[str, dict[str, Any]]:
    """Return a SQL predicate fencing source writes to the active attempt."""
    fence = _invocation.get()
    if fence is None or fence.source_kind != source_kind or fence.source_id != source_id:
        return "", {}
    return (
        " AND lifecycle_version = :fence_source_version"
        " AND active_actor_token = :fence_execution_token AND status = 'running'",
        {
            "fence_source_version": fence.source_version,
            "fence_execution_token": fence.execution_token,
        },
    )


@dataclass(frozen=True)
class DomainOutcome:
    status: DomainStatus
    actor_name: str
    source_kind: str
    source_id: int
    actor_execution_id: int | None = None
    attempt: int | None = None
    retry_at: str | None = None
    error_type: str | None = None

    def as_dict(self) -> dict[str, Any]:
        return {
            "protocol": PROTOCOL_NAME,
            "version": PROTOCOL_VERSION,
            "status": self.status.value,
            "actor": self.actor_name,
            "source": {"kind": self.source_kind, "id": self.source_id},
            "actor_execution_id": self.actor_execution_id,
            "attempt": self.attempt,
            "retry_at": self.retry_at,
            "error_type": sanitize_error(self.error_type),
        }

    def encode(self) -> str:
        return json.dumps(self.as_dict(), separators=(",", ":"), sort_keys=True)


def protocol_failure(
    *, actor_name: str, source_kind: str, source_id: int, error_type: str
) -> DomainOutcome:
    return DomainOutcome(
        DomainStatus.PROTOCOL_FAILURE,
        actor_name,
        source_kind,
        source_id,
        error_type=sanitize_error(error_type) or "protocol_failure",
    )


def sanitize_error(value: object) -> str | None:
    if value is None:
        return None
    text = str(value).strip().replace("\r", " ").replace("\n", " ")
    return text[:MAX_ERROR_LENGTH] or None


def transition_allowed(current: str, target: str) -> bool:
    return target in TRANSITION_MATRIX.get(current, frozenset())


class ExecutionLifecycle:
    """Only Python API for activation, transitions, retry and canonical audit events."""

    def __init__(self, handle: ActorExecutionHandle):
        self.handle = handle

    @classmethod
    def start(cls, **identity: Any) -> ExecutionLifecycle:
        fence = _invocation.get()
        if fence is not None:
            source_column = {
                "pipeline_run": "pipeline_run_id",
                "command": "command_id",
                "webhook_delivery": "webhook_delivery_id",
            }[fence.source_kind]
            if (
                identity.get("actor_name") != fence.execution_actor_name
                or identity.get(source_column) != fence.source_id
            ):
                raise RuntimeError("actor lifecycle identity does not match invocation fence")
            identity.update(
                execution_token=fence.execution_token,
                source_version=fence.source_version,
                actor_execution_id=fence.actor_execution_id,
                expected_attempt=fence.attempt,
            )
        lifecycle = cls(execution_store.start_actor_execution(**identity))
        lifecycle._emit(
            "actor.started",
            message="Actor execution started.",
            payload={"attempt": lifecycle.handle.attempt},
        )
        return lifecycle

    def _event_identity(self) -> dict[str, int]:
        if self.handle.source_kind is None or self.handle.source_id is None:
            return {}
        key = {
            "pipeline_run": "pipeline_run_id",
            "command": "command_id",
            "webhook_delivery": "webhook_delivery_id",
        }[self.handle.source_kind]
        return {key: self.handle.source_id}

    def _emit(
        self,
        event_type: str,
        *,
        level: str = "info",
        message: str,
        payload: dict[str, Any] | None = None,
    ) -> None:
        """Emit canonical events only for the fenced productive transport."""
        if self.handle.execution_token is None:
            return
        from app.events.publish import publish_pipeline_event

        publish_pipeline_event(
            event_type,
            level=level,
            message=message,
            payload={
                "actor_execution_id": self.handle.id,
                "actor_name": self.handle.actor_name,
                **(payload or {}),
            },
            **self._event_identity(),
        )

    def progress(self, snapshot: ProgressSnapshot, current_item: str | None = None) -> None:
        if self.handle.id is not None:
            update_actor_execution_progress(self.handle.id, snapshot, current_item)

    def finish(
        self, status: str, *, error_type: str | None = None, error_message: str | None = None
    ) -> None:
        if not transition_allowed("running", status):
            raise ValueError(f"invalid actor execution final status: {status}")
        clean_type = sanitize_error(error_type)
        changed = execution_store.finish_actor_execution(
            self.handle,
            status=status,
            error_type=clean_type,
            error_message=sanitize_error(error_message),
        )
        if changed is False:
            return
        event_type = "actor.succeeded" if status == "succeeded" else "actor.failed"
        level = "info" if status in {"succeeded", "skipped", "blocked", "cancelled"} else "error"
        self._emit(
            event_type,
            level=level,
            message=f"Actor execution finished with status {status}.",
            payload={"status": status, "error_type": clean_type},
        )

    def schedule_retry(
        self, exc: BaseException, *, max_attempts: int = 5
    ) -> tuple[RetryClass, int] | None:
        disposition = self.fail(exc, max_attempts=max_attempts)
        if not disposition.retrying:
            return None
        return disposition.retry_class, int(disposition.backoff_seconds or 0)

    def fail(self, exc: BaseException, *, max_attempts: int = 5) -> FailureDisposition:
        """Classify and durably finalize one failure exactly once."""
        retry_class = classify_exception(exc)
        if should_retry(retry_class, attempt=self.handle.attempt, max_attempts=max_attempts):
            backoff = retry_backoff_seconds(self.handle.attempt)
            changed = execution_store.schedule_actor_execution_retry(
                self.handle,
                retry_class=retry_class.value,
                retry_reason=type(exc).__name__,
                backoff_seconds=backoff,
                error_message=sanitize_error(exc),
            )
            if changed is False:
                return FailureDisposition(True, retry_class, backoff)
            self._emit(
                "actor.retry_scheduled",
                level="warning",
                message="Actor retry scheduled for Laravel recovery.",
                payload={
                    "retry_class": retry_class.value,
                    "retry_reason": type(exc).__name__,
                    "backoff_seconds": backoff,
                },
            )
            return FailureDisposition(True, retry_class, backoff)
        changed = execution_store.finish_actor_execution(
            self.handle,
            status="failed_permanent",
            error_type=retry_class.value,
            error_message=sanitize_error(exc),
        )
        if changed is False:
            return FailureDisposition(False, retry_class)
        self._emit(
            "actor.failed",
            level="error",
            message="Actor execution failed permanently.",
            payload={"status": "failed_permanent", "error_type": retry_class.value},
        )
        return FailureDisposition(False, retry_class)


def start_actor_execution(**identity: Any) -> ActorExecutionHandle:
    return ExecutionLifecycle.start(**identity).handle


def finish_actor_execution(
    handle: ActorExecutionHandle,
    *,
    status: str,
    error_type: str | None = None,
    error_message: str | None = None,
) -> None:
    ExecutionLifecycle(handle).finish(status, error_type=error_type, error_message=error_message)


def schedule_actor_execution_retry(
    handle: ActorExecutionHandle,
    *,
    retry_class: str,
    retry_reason: str,
    backoff_seconds: int,
    error_message: str | None = None,
) -> None:
    execution_store.schedule_actor_execution_retry(
        handle,
        retry_class=retry_class,
        retry_reason=retry_reason,
        backoff_seconds=backoff_seconds,
        error_message=sanitize_error(error_message),
    )


def update_actor_execution_progress(
    actor_execution_id: int, snapshot: ProgressSnapshot, current_item: str | None = None
) -> None:
    from app.jobs.progress import update_actor_execution_progress as update

    update(actor_execution_id, snapshot, current_item=current_item)


def update_item_derived_progress(
    *,
    pipeline_run_id: int,
    actor_execution_id: int | None,
    phase: str,
    message: str,
    current_item: str | None = None,
) -> ProgressSnapshot:
    from app.jobs.pipeline_items import progress_from_pipeline_items
    from app.jobs.progress import update_pipeline_run_progress

    total, done, failed, skipped = progress_from_pipeline_items(pipeline_run_id)
    snapshot = ProgressSnapshot(
        total=total, done=done, failed=failed, skipped=skipped, phase=phase, message=message
    )
    update_pipeline_run_progress(pipeline_run_id, snapshot)
    if actor_execution_id is not None:
        update_actor_execution_progress(actor_execution_id, snapshot, current_item=current_item)
    return snapshot


def recover_stale_executions(*, stale_after_seconds: int = 900, limit: int = 100) -> int:
    """Close stale attempts; Laravel alone decides and dispatches the next claim."""
    from app.events.publish import publish_pipeline_event

    recovered = 0
    for execution in execution_store.list_stale_running_actor_executions(
        stale_after_seconds=stale_after_seconds, limit=limit
    ):
        source = next(
            (
                (kind, source_id)
                for kind, source_id in (
                    ("pipeline_run", execution.pipeline_run_id),
                    ("command", execution.command_id),
                    ("webhook_delivery", execution.webhook_delivery_id),
                )
                if source_id is not None
            ),
            (None, None),
        )
        handle = ActorExecutionHandle(
            execution.id,
            execution.actor_name,
            time.monotonic(),
            execution.attempt,
            execution.execution_token,
            source[0],
            source[1],
            execution.source_version,
        )
        try:
            execution_store.schedule_actor_execution_retry(
                handle,
                retry_class="worker_recovery_stale_actor",
                retry_reason="worker_recovery_stale_actor",
                backoff_seconds=0,
                error_message="Actor execution was left running and recovered after worker restart.",
            )
        except RuntimeError:
            # The actor won the race and completed after the stale scan. Its
            # fenced terminal state must win over recovery.
            continue
        identity: dict[str, int] = {}
        if source[0] is not None and source[1] is not None:
            identity[
                {
                    "pipeline_run": "pipeline_run_id",
                    "command": "command_id",
                    "webhook_delivery": "webhook_delivery_id",
                }[source[0]]
            ] = source[1]
        publish_pipeline_event(
            "actor.recovered_stale",
            level="warning",
            message="Stale actor execution recovered after worker restart.",
            payload={
                "actor_execution_id": execution.id,
                "actor_name": execution.actor_name,
                "retry_mode": "recovery",
            },
            **identity,
        )
        recovered += 1
    return recovered


def finalize_cancel_requests(*, limit: int = 100) -> int:
    from app.events.publish import publish_pipeline_event
    from app.jobs.pipeline_runs import (
        list_cancel_requested_pipeline_run_ids,
        mark_pipeline_run_cancelled,
    )

    ids = list_cancel_requested_pipeline_run_ids(limit=limit)
    for pipeline_run_id in ids:
        mark_pipeline_run_cancelled(pipeline_run_id)
        publish_pipeline_event(
            "pipeline.cancelled",
            pipeline_run_id=pipeline_run_id,
            level="warning",
            message="Pipeline run cancelled by admin request.",
        )
    return len(ids)


def run_recovery_transition_scan(*, limit: int = 100) -> tuple[int, int]:
    """Canonical Python recovery is transition-only; Laravel owns redispatch."""
    return recover_stale_executions(limit=limit), finalize_cancel_requests(limit=limit)


def retired_python_dispatch(operation: str) -> None:
    raise RuntimeError(
        f"{operation} is retired: Laravel database queues own fenced actor redispatch"
    )


def outcome_for_source(
    *,
    actor_name: str,
    source_kind: str,
    source_id: int,
    execution_actor_name: str | None = None,
    execution_token: str | None = None,
    source_version: int | None = None,
) -> DomainOutcome | None:
    """Load only the invocation's durable execution; never infer an outcome."""
    source_column = {
        "pipeline_run": "pipeline_run_id",
        "command": "command_id",
        "webhook_delivery": "webhook_delivery_id",
    }.get(source_kind)
    if source_column is None:
        raise ValueError(f"Unsupported durable source kind: {source_kind}")
    from sqlalchemy import text

    token_predicate = (
        " AND execution_token = :execution_token AND source_version = :source_version"
        if execution_token is not None
        else ""
    )
    with engine().connect() as connection:
        row = (
            connection.execute(
                text(f"""SELECT id, status, attempt, next_retry_at, error_type
            FROM actor_executions WHERE actor_name = :actor_name AND {source_column} = :source_id
            {token_predicate} ORDER BY id DESC LIMIT 1"""),
                {
                    "actor_name": execution_actor_name or actor_name,
                    "source_id": source_id,
                    "execution_token": execution_token,
                    "source_version": source_version,
                },
            )
            .mappings()
            .first()
        )
    if row is None:
        return None
    status = str(row["status"])
    mapped = {
        "succeeded": DomainStatus.SUCCEEDED,
        "skipped": DomainStatus.SKIPPED,
        "blocked": DomainStatus.BLOCKED,
        "cancelled": DomainStatus.CANCELLED,
        "retrying": DomainStatus.RETRYING,
        "failed_permanent": DomainStatus.FAILED_PERMANENT,
    }.get(status)
    if mapped is None:
        return None
    retry_at = row["next_retry_at"]
    if isinstance(retry_at, datetime):
        retry_at = retry_at.isoformat()
    error_type = None if row["error_type"] is None else str(row["error_type"])
    if (
        mapped
        in {
            DomainStatus.SKIPPED,
            DomainStatus.BLOCKED,
            DomainStatus.CANCELLED,
            DomainStatus.FAILED_PERMANENT,
        }
        and not error_type
    ):
        return None
    return DomainOutcome(
        mapped,
        actor_name,
        source_kind,
        source_id,
        int(row["id"]),
        int(row["attempt"]),
        None if retry_at is None else str(retry_at),
        error_type,
    )
