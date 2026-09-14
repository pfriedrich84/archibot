"""Temporal activities for discovery and per-document review production."""

from __future__ import annotations

import asyncio
import hashlib
import json
from collections.abc import Awaitable
from contextlib import suppress
from datetime import UTC, datetime

from temporalio import activity

from app.actors.document import _classify_document
from app.ai_provider.factory import create_ai_provider
from app.clients.paperless import PaperlessClient
from app.config import settings
from app.jobs.commands import sql_text
from app.jobs.database import engine
from app.jobs.review_suggestions import classified_document_ids, store_review_suggestion
from app.temporal.contracts import (
    DocumentProcessResult,
    DocumentReadiness,
    DocumentWorkflowStart,
    PollDiscoveryResult,
    PollWorkflowRequest,
    PollWorkflowResult,
)

_HEARTBEAT_SECONDS = 30


async def _await_with_heartbeats[T](awaitable: Awaitable[T], details: dict[str, int]) -> T:
    task = asyncio.create_task(awaitable)
    try:
        while not task.done():
            done, _ = await asyncio.wait({task}, timeout=_HEARTBEAT_SECONDS)
            if not done:
                activity.heartbeat(details)
        return await task
    finally:
        if not task.done():
            task.cancel()
            with suppress(asyncio.CancelledError):
                await task


def _modified_value(value: datetime | None) -> str | None:
    if value is None:
        return None
    if value.tzinfo is None:
        value = value.replace(tzinfo=UTC)
    return value.astimezone(UTC).strftime("%Y-%m-%dT%H:%M:%S.%fZ")


def _document_dedupe_key(
    paperless_document_id: int,
    modified: str | None,
    *,
    force_command_id: int | None = None,
) -> str:
    parts = [
        str(paperless_document_id),
        modified or "unknown_modified",
        "unknown_content",
    ]
    if force_command_id is not None:
        parts = ["force", *parts, f"poll-command-{force_command_id}"]
    parts.append("v1")
    return hashlib.sha256(":".join(parts).encode()).hexdigest()


def _load_poll_command(command_id: int) -> tuple[int | None, bool]:
    with engine().begin() as connection:
        row = (
            connection.execute(
                sql_text(
                    """
                    SELECT status, payload
                    FROM commands
                    WHERE id = :command_id AND type = 'poll_reconciliation'
                    FOR UPDATE
                    """
                ),
                {"command_id": command_id},
            )
            .mappings()
            .first()
        )
        if row is None:
            raise ValueError(f"Poll command {command_id} does not exist")
        payload = row["payload"] if isinstance(row["payload"], dict) else {}
        if payload.get("orchestration_driver") != "temporal":
            raise ValueError(f"Poll command {command_id} is not owned by Temporal")
        raw_limit = payload.get("limit")
        limit = int(raw_limit) if raw_limit not in (None, "") and int(raw_limit) > 0 else None
        connection.execute(
            sql_text(
                """
                UPDATE commands
                SET status = 'running', started_at = COALESCE(started_at, CURRENT_TIMESTAMP),
                    finished_at = NULL, error = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = :command_id
                """
            ),
            {"command_id": command_id},
        )
    return limit, bool(payload.get("force", False))


def _embedding_ready(connection) -> bool:
    row = (
        connection.execute(
            sql_text(
                """
                SELECT status FROM embedding_index_state
                ORDER BY completed_at DESC NULLS LAST, updated_at DESC, id DESC
                LIMIT 1
                """
            )
        )
        .mappings()
        .first()
    )
    return row is not None and str(row["status"]) == "complete"


def _persist_observation_and_run(
    *, command_id: int, paperless_document_id: int, modified: str | None, force: bool
) -> DocumentWorkflowStart | None:
    dedupe_key = _document_dedupe_key(
        paperless_document_id,
        modified,
        force_command_id=command_id if force else None,
    )
    workflow_id = f"archibot/document/{paperless_document_id}/{dedupe_key}"
    with engine().begin() as connection:
        gate_open = _embedding_ready(connection)
        status = "queued" if gate_open else "blocked"
        phase = "document_activity" if gate_open else "waiting_for_embedding"
        message = (
            "Waiting for Temporal document activity."
            if gate_open
            else "Temporal workflow is waiting for embedding readiness."
        )
        connection.execute(
            sql_text(
                """
                INSERT INTO pipeline_runs (
                    command_id, batch_command_id, type, status, scope, trigger_source,
                    orchestration_driver, temporal_workflow_id, paperless_document_id,
                    paperless_modified, pipeline_dedupe_key, coalesced_sources,
                    progress_current_phase, progress_message, progress_updated_at,
                    reprocess_requested, reprocess_reason, reprocess_mode,
                    created_at, updated_at
                ) VALUES (
                    NULL, NULL, 'document', :status, 'single_document', 'poll',
                    'temporal', :workflow_id, :paperless_document_id,
                    :modified, :dedupe_key, CAST('["poll"]' AS json),
                    :phase, :message, CURRENT_TIMESTAMP,
                    :force, :reprocess_reason, :reprocess_mode,
                    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                ON CONFLICT (paperless_document_id, pipeline_dedupe_key) DO NOTHING
                """
            ),
            {
                "status": status,
                "workflow_id": workflow_id,
                "paperless_document_id": paperless_document_id,
                "modified": modified,
                "dedupe_key": dedupe_key,
                "phase": phase,
                "message": message,
                "force": force,
                "reprocess_reason": "forced_poll_reconciliation" if force else None,
                "reprocess_mode": "poll_force" if force else None,
            },
        )
        run = (
            connection.execute(
                sql_text(
                    """
                    SELECT id, status, orchestration_driver, temporal_workflow_id
                    FROM pipeline_runs
                    WHERE paperless_document_id = :paperless_document_id
                      AND pipeline_dedupe_key = :dedupe_key
                    FOR UPDATE
                    """
                ),
                {"paperless_document_id": paperless_document_id, "dedupe_key": dedupe_key},
            )
            .mappings()
            .one()
        )
        if run["orchestration_driver"] is None and str(run["status"]) in {"pending", "blocked"}:
            adopted = connection.execute(
                sql_text(
                    """
                    UPDATE pipeline_runs
                    SET command_id = NULL, batch_command_id = NULL,
                        orchestration_driver = 'temporal', temporal_workflow_id = :workflow_id,
                        status = :status, progress_current_phase = :phase,
                        progress_message = :message, progress_updated_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :pipeline_run_id
                      AND orchestration_driver IS NULL
                      AND status IN ('pending', 'blocked')
                    """
                ),
                {
                    "pipeline_run_id": int(run["id"]),
                    "workflow_id": workflow_id,
                    "status": status,
                    "phase": phase,
                    "message": message,
                },
            )
            if adopted.rowcount == 1:
                run = {
                    **run,
                    "orchestration_driver": "temporal",
                    "temporal_workflow_id": workflow_id,
                }

        pipeline_run_id = int(run["id"])
        connection.execute(
            sql_text(
                """
                INSERT INTO document_observations (
                    paperless_document_id, paperless_modified, content_hash, version_key,
                    source, source_command_id, pipeline_run_id, observed_at, created_at, updated_at
                ) VALUES (
                    :paperless_document_id, :modified, NULL, :version_key,
                    'poll', :command_id, :pipeline_run_id, CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                ON CONFLICT (paperless_document_id, version_key)
                DO UPDATE SET source = EXCLUDED.source,
                              source_command_id = EXCLUDED.source_command_id,
                              pipeline_run_id = COALESCE(document_observations.pipeline_run_id, EXCLUDED.pipeline_run_id),
                              observed_at = CURRENT_TIMESTAMP,
                              updated_at = CURRENT_TIMESTAMP
                """
            ),
            {
                "paperless_document_id": paperless_document_id,
                "modified": modified,
                "version_key": dedupe_key,
                "command_id": command_id,
                "pipeline_run_id": pipeline_run_id,
            },
        )
        if run["orchestration_driver"] != "temporal":
            return None
        return DocumentWorkflowStart(
            pipeline_run_id=pipeline_run_id,
            workflow_id=str(run["temporal_workflow_id"]),
        )


@activity.defn(name="archibot.discover_inbox_documents")
async def discover_inbox_documents(request: PollWorkflowRequest) -> PollDiscoveryResult:
    limit, force = await asyncio.to_thread(_load_poll_command, request.command_id)
    if settings.paperless_inbox_tag_id <= 0:
        return PollDiscoveryResult(request.command_id, 0, 0, [], "skipped")
    paperless = PaperlessClient()
    try:
        documents = await _await_with_heartbeats(
            paperless.list_inbox_documents(settings.paperless_inbox_tag_id),
            {"command_id": request.command_id},
        )
    finally:
        await paperless.aclose()
    if limit is not None:
        documents = documents[:limit]

    marked = (
        set()
        if force
        else await asyncio.to_thread(
            classified_document_ids, [int(document.id) for document in documents]
        )
    )
    starts: list[DocumentWorkflowStart] = []
    skipped = 0
    for document in documents:
        document_id = int(document.id)
        if document_id in marked:
            skipped += 1
            continue
        start = await asyncio.to_thread(
            _persist_observation_and_run,
            command_id=request.command_id,
            paperless_document_id=document_id,
            modified=_modified_value(document.modified),
            force=force,
        )
        if start is None:
            skipped += 1
        else:
            starts.append(start)
    return PollDiscoveryResult(request.command_id, len(documents), skipped, starts, "succeeded")


@activity.defn(name="archibot.finish_poll_discovery")
async def finish_poll_discovery(result: PollWorkflowResult) -> PollWorkflowResult:
    await asyncio.to_thread(_finish_poll_discovery, result)
    return result


def _finish_poll_discovery(result: PollWorkflowResult) -> None:
    with engine().begin() as connection:
        updated = connection.execute(
            sql_text(
                """
                UPDATE commands
                SET status = CAST(:status AS character varying), finished_at = CURRENT_TIMESTAMP,
                    error = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = :command_id
                  AND payload->>'orchestration_driver' = 'temporal'
                """
            ),
            {"command_id": result.command_id, "status": result.status},
        )
        if updated.rowcount != 1:
            raise RuntimeError(f"Temporal poll command {result.command_id} is missing")
        connection.execute(
            sql_text(
                """
                INSERT INTO pipeline_events (
                    command_id, event_type, level, message, payload, created_at
                ) VALUES (
                    :command_id, 'poll.reconciliation.completed', 'info',
                    'Temporal polling discovery completed.',
                    CAST(:payload AS json), CURRENT_TIMESTAMP
                )
                """
            ),
            {
                "command_id": result.command_id,
                "payload": json.dumps(
                    {
                        "documents_seen": result.documents_seen,
                        "documents_started": result.documents_started,
                        "documents_skipped": result.documents_skipped,
                    },
                    separators=(",", ":"),
                ),
            },
        )


@activity.defn(name="archibot.fail_poll_discovery")
async def fail_poll_discovery(command_id: int) -> None:
    await asyncio.to_thread(_fail_poll_discovery, command_id)


def _fail_poll_discovery(command_id: int) -> None:
    with engine().begin() as connection:
        connection.execute(
            sql_text(
                """
                UPDATE commands
                SET status = 'failed_permanent', finished_at = CURRENT_TIMESTAMP,
                    error = 'Temporal polling discovery exhausted its retries.',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :command_id
                  AND payload->>'orchestration_driver' = 'temporal'
                  AND status NOT IN ('succeeded', 'skipped')
                """
            ),
            {"command_id": command_id},
        )


@activity.defn(name="archibot.check_document_readiness")
async def check_document_readiness(pipeline_run_id: int) -> DocumentReadiness:
    return await asyncio.to_thread(_check_document_readiness, pipeline_run_id)


def _check_document_readiness(pipeline_run_id: int) -> DocumentReadiness:
    with engine().begin() as connection:
        run = (
            connection.execute(
                sql_text(
                    """
                    SELECT status FROM pipeline_runs
                    WHERE id = :pipeline_run_id
                      AND type = 'document'
                      AND orchestration_driver = 'temporal'
                    FOR UPDATE
                    """
                ),
                {"pipeline_run_id": pipeline_run_id},
            )
            .mappings()
            .first()
        )
        if run is None:
            raise ValueError(f"Temporal document pipeline run {pipeline_run_id} does not exist")
        status = str(run["status"])
        suggestion = (
            connection.execute(
                sql_text(
                    "SELECT id FROM review_suggestions WHERE pipeline_run_id = :pipeline_run_id LIMIT 1"
                ),
                {"pipeline_run_id": pipeline_run_id},
            )
            .mappings()
            .first()
        )
        if status == "succeeded" and suggestion is not None:
            return DocumentReadiness(pipeline_run_id, "complete", int(suggestion["id"]))
        if status in {"cancel_requested", "cancelled"}:
            return DocumentReadiness(pipeline_run_id, "cancelled")
        if _embedding_ready(connection):
            connection.execute(
                sql_text(
                    """
                    UPDATE pipeline_runs
                    SET status = 'queued', progress_current_phase = 'document_activity',
                        progress_message = 'Embedding index ready; waiting for Temporal document activity.',
                        progress_updated_at = CURRENT_TIMESTAMP, error_type = NULL, error = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :pipeline_run_id AND status IN ('pending', 'blocked', 'retrying')
                    """
                ),
                {"pipeline_run_id": pipeline_run_id},
            )
            return DocumentReadiness(pipeline_run_id, "ready")
        connection.execute(
            sql_text(
                """
                UPDATE pipeline_runs
                SET status = 'blocked', progress_current_phase = 'waiting_for_embedding',
                    progress_message = 'Temporal workflow is waiting for embedding readiness.',
                    progress_updated_at = CURRENT_TIMESTAMP,
                    error_type = 'embedding_index_not_ready',
                    error = 'Waiting for embedding index to complete.',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :pipeline_run_id AND status <> 'blocked'
                """
            ),
            {"pipeline_run_id": pipeline_run_id},
        )
        return DocumentReadiness(pipeline_run_id, "waiting")


@activity.defn(name="archibot.process_document_for_review")
async def process_document_for_review(pipeline_run_id: int) -> DocumentProcessResult:
    existing = await asyncio.to_thread(_existing_document_result, pipeline_run_id)
    if existing is not None:
        return existing
    await asyncio.to_thread(_mark_document_running, pipeline_run_id)
    paperless = PaperlessClient()
    provider = create_ai_provider()
    try:
        document_id = await asyncio.to_thread(_paperless_document_id, pipeline_run_id)
        document = await _await_with_heartbeats(
            paperless.get_document(document_id), {"pipeline_run_id": pipeline_run_id}
        )
        outcome = await _await_with_heartbeats(
            _classify_document(document, paperless=paperless, ai_provider=provider),
            {"pipeline_run_id": pipeline_run_id},
        )
        suggestion = await asyncio.to_thread(
            store_review_suggestion,
            paperless_document_id=document_id,
            document=outcome.document,
            result=outcome.result,
            raw_response=outcome.raw_response,
            context_documents=outcome.context_documents,
            pipeline_run_id=pipeline_run_id,
            correspondents=outcome.catalog.correspondents,
            doctypes=outcome.catalog.doctypes,
            storage_paths=outcome.catalog.storage_paths,
            tags=outcome.catalog.tags,
            judge_verdict=outcome.judge_verdict,
            judge_reasoning=outcome.judge_reasoning,
            original_proposed_json=outcome.original_proposed_json,
        )
    finally:
        await provider.aclose()
        await paperless.aclose()
    await asyncio.to_thread(
        _mark_document_review_ready, pipeline_run_id, suggestion.id, document_id
    )
    return DocumentProcessResult(pipeline_run_id, suggestion.id, "awaiting_review")


def _paperless_document_id(pipeline_run_id: int) -> int:
    with engine().connect() as connection:
        row = (
            connection.execute(
                sql_text(
                    "SELECT paperless_document_id FROM pipeline_runs WHERE id = :pipeline_run_id"
                ),
                {"pipeline_run_id": pipeline_run_id},
            )
            .mappings()
            .first()
        )
    if row is None or row["paperless_document_id"] is None:
        raise ValueError(f"Document pipeline run {pipeline_run_id} has no Paperless document")
    return int(row["paperless_document_id"])


def _existing_document_result(pipeline_run_id: int) -> DocumentProcessResult | None:
    with engine().connect() as connection:
        row = (
            connection.execute(
                sql_text(
                    """
                    SELECT pipeline_runs.status,
                           pipeline_runs.paperless_document_id,
                           review_suggestions.id AS suggestion_id
                    FROM pipeline_runs
                    LEFT JOIN review_suggestions
                      ON review_suggestions.pipeline_run_id = pipeline_runs.id
                    WHERE pipeline_runs.id = :pipeline_run_id
                      AND pipeline_runs.orchestration_driver = 'temporal'
                    LIMIT 1
                    """
                ),
                {"pipeline_run_id": pipeline_run_id},
            )
            .mappings()
            .first()
        )
    if row is None:
        raise ValueError(f"Temporal document pipeline run {pipeline_run_id} does not exist")
    if row["suggestion_id"] is not None:
        suggestion_id = int(row["suggestion_id"])
        if str(row["status"]) != "succeeded":
            _mark_document_review_ready(
                pipeline_run_id,
                suggestion_id,
                int(row["paperless_document_id"]),
            )
        return DocumentProcessResult(pipeline_run_id, suggestion_id, "awaiting_review")
    return None


def _mark_document_running(pipeline_run_id: int) -> None:
    with engine().begin() as connection:
        updated = connection.execute(
            sql_text(
                """
                UPDATE pipeline_runs
                SET status = 'running', started_at = COALESCE(started_at, CURRENT_TIMESTAMP),
                    progress_current_phase = 'classification',
                    progress_message = 'Temporal document activity is producing a review.',
                    progress_updated_at = CURRENT_TIMESTAMP, error_type = NULL, error = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :pipeline_run_id
                  AND orchestration_driver = 'temporal'
                  AND status IN ('pending', 'blocked', 'queued', 'running', 'retrying')
                """
            ),
            {"pipeline_run_id": pipeline_run_id},
        )
        if updated.rowcount != 1:
            raise RuntimeError(f"Temporal document pipeline run {pipeline_run_id} is not runnable")


def _mark_document_review_ready(
    pipeline_run_id: int, review_suggestion_id: int, paperless_document_id: int
) -> None:
    with engine().begin() as connection:
        updated = connection.execute(
            sql_text(
                """
                UPDATE pipeline_runs
                SET status = 'succeeded', progress_total = 1, progress_done = 1,
                    progress_current_phase = 'review_suggestion',
                    progress_message = 'Review suggestion persisted for manual review.',
                    progress_updated_at = CURRENT_TIMESTAMP, finished_at = CURRENT_TIMESTAMP,
                    error_type = NULL, error = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = :pipeline_run_id AND orchestration_driver = 'temporal'
                  AND status <> 'succeeded'
                """
            ),
            {"pipeline_run_id": pipeline_run_id},
        )
        if updated.rowcount != 1:
            current = connection.execute(
                sql_text(
                    """
                    SELECT status FROM pipeline_runs
                    WHERE id = :pipeline_run_id AND orchestration_driver = 'temporal'
                    """
                ),
                {"pipeline_run_id": pipeline_run_id},
            ).scalar_one_or_none()
            if str(current) == "succeeded":
                return
            raise RuntimeError(f"Temporal document pipeline run {pipeline_run_id} is missing")
        connection.execute(
            sql_text(
                """
                INSERT INTO pipeline_events (
                    pipeline_run_id, event_type, paperless_document_id,
                    level, message, payload, created_at
                ) VALUES (
                    :pipeline_run_id, 'document.review_suggestion.stored',
                    :paperless_document_id, 'info',
                    'Review suggestion persisted for manual review by Temporal workflow.',
                    CAST(:payload AS jsonb), CURRENT_TIMESTAMP
                )
                """
            ),
            {
                "pipeline_run_id": pipeline_run_id,
                "paperless_document_id": paperless_document_id,
                "payload": json.dumps(
                    {"review_suggestion_id": review_suggestion_id, "status": "pending"},
                    separators=(",", ":"),
                ),
            },
        )


@activity.defn(name="archibot.fail_document_processing")
async def fail_document_processing(pipeline_run_id: int) -> None:
    await asyncio.to_thread(_fail_document_processing, pipeline_run_id)


def _fail_document_processing(pipeline_run_id: int) -> None:
    with engine().begin() as connection:
        connection.execute(
            sql_text(
                """
                UPDATE pipeline_runs
                SET status = 'failed_permanent', finished_at = CURRENT_TIMESTAMP,
                    progress_current_phase = 'failed',
                    progress_message = 'Temporal document activity exhausted its retries.',
                    error_type = 'temporal_activity_attempts_exhausted',
                    error = 'Temporal document activity exhausted its retries.',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :pipeline_run_id
                  AND orchestration_driver = 'temporal'
                  AND status NOT IN ('succeeded', 'cancelled')
                """
            ),
            {"pipeline_run_id": pipeline_run_id},
        )
