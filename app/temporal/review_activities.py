"""Temporal activities for idempotent accepted-review Paperless commits."""

from __future__ import annotations

import asyncio
import json
from collections.abc import Awaitable
from contextlib import suppress

from temporalio import activity

from app.clients.paperless import PaperlessClient
from app.jobs.commands import sql_text
from app.jobs.database import engine
from app.jobs.review_commit import (
    commit_review_suggestion_to_paperless,
    load_review_commit,
)
from app.temporal.contracts import ReviewCommitRequest, ReviewCommitResult

_HEARTBEAT_SECONDS = 30


async def _await_with_heartbeats[T](awaitable: Awaitable[T], request: ReviewCommitRequest) -> T:
    task = asyncio.create_task(awaitable)
    try:
        while not task.done():
            done, _ = await asyncio.wait({task}, timeout=_HEARTBEAT_SECONDS)
            if not done:
                activity.heartbeat(
                    {
                        "command_id": request.command_id,
                        "review_suggestion_id": request.review_suggestion_id,
                    }
                )
        return await task
    finally:
        if not task.done():
            task.cancel()
            with suppress(asyncio.CancelledError):
                await task


def _begin_review_commit(request: ReviewCommitRequest) -> bool:
    """Fence one Temporal-owned commit and return whether it already finished."""
    with engine().begin() as connection:
        row = (
            connection.execute(
                sql_text(
                    """
                    SELECT review_suggestions.status AS review_status,
                           review_suggestions.commit_status,
                           review_suggestions.commit_command_id,
                           commands.type AS command_type,
                           commands.status AS command_status,
                           commands.payload AS command_payload
                    FROM review_suggestions
                    JOIN commands ON commands.id = review_suggestions.commit_command_id
                    WHERE review_suggestions.id = :review_suggestion_id
                      AND commands.id = :command_id
                    FOR UPDATE OF review_suggestions, commands
                    """
                ),
                {
                    "review_suggestion_id": request.review_suggestion_id,
                    "command_id": request.command_id,
                },
            )
            .mappings()
            .first()
        )
        if row is None:
            raise ValueError("Accepted review commit command does not exist")
        payload = row["command_payload"] if isinstance(row["command_payload"], dict) else {}
        if (
            str(row["review_status"]) != "accepted"
            or int(row["commit_command_id"]) != request.command_id
            or str(row["command_type"]) != "review_commit"
            or payload.get("orchestration_driver") != "temporal"
            or payload.get("temporal_workflow_id") != request.workflow_id
        ):
            raise ValueError("Review commit is not owned by this Temporal workflow")
        if str(row["commit_status"]) == "committed" and str(row["command_status"]) == "succeeded":
            return True
        if str(row["command_status"]) == "failed_permanent":
            raise ValueError("Review commit command is already permanently failed")

        connection.execute(
            sql_text(
                """
                UPDATE commands
                SET status = 'running', started_at = COALESCE(started_at, CURRENT_TIMESTAMP),
                    finished_at = NULL, error = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = :command_id
                """
            ),
            {"command_id": request.command_id},
        )
        connection.execute(
            sql_text(
                """
                UPDATE review_suggestions
                SET commit_status = 'running', updated_at = CURRENT_TIMESTAMP
                WHERE id = :review_suggestion_id
                """
            ),
            {"review_suggestion_id": request.review_suggestion_id},
        )
    return False


def _finish_review_commit(request: ReviewCommitRequest, patched_fields: list[str]) -> None:
    with engine().begin() as connection:
        transitioned = connection.execute(
            sql_text(
                """
                UPDATE commands
                SET status = 'succeeded', finished_at = CURRENT_TIMESTAMP,
                    error = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = :command_id
                  AND status <> 'succeeded'
                  AND payload->>'orchestration_driver' = 'temporal'
                  AND payload->>'temporal_workflow_id' = :workflow_id
                """
            ),
            {"command_id": request.command_id, "workflow_id": request.workflow_id},
        )
        connection.execute(
            sql_text(
                """
                UPDATE review_suggestions
                SET commit_status = 'committed', updated_at = CURRENT_TIMESTAMP
                WHERE id = :review_suggestion_id
                  AND commit_command_id = :command_id
                  AND status = 'accepted'
                """
            ),
            {
                "review_suggestion_id": request.review_suggestion_id,
                "command_id": request.command_id,
            },
        )
        if transitioned.rowcount == 1:
            connection.execute(
                sql_text(
                    """
                    INSERT INTO pipeline_events (
                        command_id, event_type, level, message, payload, created_at
                    ) VALUES (
                        :command_id, 'review.commit.succeeded', 'info',
                        'Accepted review suggestion committed to Paperless by Temporal.',
                        CAST(:payload AS jsonb), CURRENT_TIMESTAMP
                    )
                    """
                ),
                {
                    "command_id": request.command_id,
                    "payload": json.dumps(
                        {
                            "review_suggestion_id": request.review_suggestion_id,
                            "patched_fields": patched_fields,
                        },
                        separators=(",", ":"),
                    ),
                },
            )


@activity.defn(name="archibot.commit_review_suggestion")
async def commit_review_suggestion(request: ReviewCommitRequest) -> ReviewCommitResult:
    already_committed = await asyncio.to_thread(_begin_review_commit, request)
    if already_committed:
        return ReviewCommitResult(request.review_suggestion_id, request.command_id, "committed", [])

    record = await asyncio.to_thread(load_review_commit, request.review_suggestion_id)
    if record is None:
        raise ValueError("Accepted review suggestion does not exist")

    paperless = PaperlessClient()
    try:
        fields = await _await_with_heartbeats(
            commit_review_suggestion_to_paperless(record, paperless), request
        )
    finally:
        await paperless.aclose()
    patched_fields = sorted(fields)
    await asyncio.to_thread(_finish_review_commit, request, patched_fields)
    return ReviewCommitResult(
        request.review_suggestion_id,
        request.command_id,
        "committed",
        patched_fields,
    )


def _fail_review_commit(request: ReviewCommitRequest) -> None:
    with engine().begin() as connection:
        committed = connection.execute(
            sql_text(
                """
                SELECT 1 FROM review_suggestions
                WHERE id = :review_suggestion_id AND commit_status = 'committed'
                """
            ),
            {"review_suggestion_id": request.review_suggestion_id},
        ).scalar_one_or_none()
        if committed is not None:
            return
        connection.execute(
            sql_text(
                """
                UPDATE commands
                SET status = 'failed_permanent', finished_at = CURRENT_TIMESTAMP,
                    error = 'Temporal review commit exhausted its activity retries.',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :command_id
                  AND payload->>'orchestration_driver' = 'temporal'
                  AND payload->>'temporal_workflow_id' = :workflow_id
                """
            ),
            {"command_id": request.command_id, "workflow_id": request.workflow_id},
        )
        connection.execute(
            sql_text(
                """
                UPDATE review_suggestions
                SET commit_status = 'failed', updated_at = CURRENT_TIMESTAMP
                WHERE id = :review_suggestion_id AND commit_command_id = :command_id
                """
            ),
            {
                "review_suggestion_id": request.review_suggestion_id,
                "command_id": request.command_id,
            },
        )
        connection.execute(
            sql_text(
                """
                INSERT INTO pipeline_events (
                    command_id, event_type, level, message, payload, created_at
                ) VALUES (
                    :command_id, 'review.commit.failed', 'error',
                    'Temporal review commit exhausted its activity retries.',
                    CAST(:payload AS jsonb), CURRENT_TIMESTAMP
                )
                """
            ),
            {
                "command_id": request.command_id,
                "payload": json.dumps(
                    {"review_suggestion_id": request.review_suggestion_id},
                    separators=(",", ":"),
                ),
            },
        )


@activity.defn(name="archibot.fail_review_commit")
async def fail_review_commit(request: ReviewCommitRequest) -> None:
    await asyncio.to_thread(_fail_review_commit, request)
