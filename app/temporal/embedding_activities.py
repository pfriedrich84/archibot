"""Idempotent activities for Temporal-owned embedding generations."""

from __future__ import annotations

import asyncio
import json
import uuid
from collections.abc import Awaitable
from contextlib import suppress

from temporalio import activity

from app.ai_provider.factory import create_ai_provider
from app.clients.paperless import PaperlessClient
from app.config import settings
from app.jobs.commands import sql_text
from app.jobs.database import engine
from app.jobs.document_embeddings import (
    DocumentEmbeddingInput,
    content_hash_for_text,
    document_embedding_exists,
    document_embedding_text,
    store_document_embedding,
)
from app.models import (
    document_date_for,
    document_version_checksum_for,
    document_version_id_for,
)
from app.pipeline.trusted_context import is_trusted_document
from app.temporal.contracts import (
    EmbeddingPreparationFailure,
    EmbeddingProgress,
    EmbeddingWorkflowRequest,
    EmbeddingWorkflowResult,
    EmbedDocumentRequest,
    EmbedDocumentResult,
    PreparedEmbeddingBuild,
)
from app.temporal.model_capacity import serialized_model_activity

_EMBEDDING_FENCE_KEY = 4_701_142_607_001
_EMBEDDING_HEARTBEAT_SECONDS = 30


def _intent_key(value: str) -> str:
    return str(uuid.uuid5(uuid.NAMESPACE_URL, f"archibot:{value}"))


async def _await_with_heartbeats[T](awaitable: Awaitable[T], details: dict[str, int]) -> T:
    task = asyncio.create_task(awaitable)
    try:
        while not task.done():
            done, _ = await asyncio.wait({task}, timeout=_EMBEDDING_HEARTBEAT_SECONDS)
            if not done:
                activity.heartbeat(details)
        return await task
    finally:
        if not task.done():
            task.cancel()
            with suppress(asyncio.CancelledError):
                await task


def _command_limit(command_id: int) -> int | None:
    with engine().connect() as connection:
        row = (
            connection.execute(
                sql_text("SELECT payload FROM commands WHERE id = :command_id"),
                {"command_id": command_id},
            )
            .mappings()
            .first()
        )
    if row is None:
        raise ValueError(f"Embedding command {command_id} does not exist")
    payload = row["payload"] if isinstance(row["payload"], dict) else {}
    raw_limit = payload.get("limit")
    if raw_limit in (None, ""):
        return None
    limit = int(raw_limit)
    return limit if limit > 0 else None


def _prepare_build_projection(command_id: int, embedding_model: str | None = None) -> int:
    """Close the old gate and create or resume this command's generation atomically."""
    with engine().begin() as connection:
        command = (
            connection.execute(
                sql_text(
                    """
                    SELECT type, status, payload
                    FROM commands
                    WHERE id = :command_id
                    FOR UPDATE
                    """
                ),
                {"command_id": command_id},
            )
            .mappings()
            .first()
        )
        if command is None:
            raise ValueError(f"Embedding command {command_id} does not exist")
        if command["type"] not in {"embedding_index_build", "reindex"}:
            raise ValueError(f"Command {command_id} is not an embedding generation")
        payload = command["payload"] if isinstance(command["payload"], dict) else {}
        if payload.get("orchestration_driver") != "temporal":
            raise ValueError(f"Command {command_id} is not owned by Temporal")

        connection.execute(
            sql_text("SELECT pg_advisory_xact_lock(:key)"), {"key": _EMBEDDING_FENCE_KEY}
        )
        existing = (
            connection.execute(
                sql_text(
                    """
                    SELECT id
                    FROM embedding_index_state
                    WHERE command_id = :command_id
                    ORDER BY id DESC
                    LIMIT 1
                    """
                ),
                {"command_id": command_id},
            )
            .mappings()
            .first()
        )
        if existing is None:
            running_other = connection.execute(
                sql_text(
                    """
                    SELECT id FROM embedding_index_state
                    WHERE status = 'building'
                      AND (command_id IS NULL OR command_id <> :command_id)
                    LIMIT 1
                    """
                ),
                {"command_id": command_id},
            ).first()
            if running_other is not None:
                raise RuntimeError("Another embedding generation is still building")
            existing = (
                connection.execute(
                    sql_text(
                        """
                        INSERT INTO embedding_index_state (
                            command_id, status, embedding_model, content_scope, started_at,
                            document_count, embedded_count, failed_count, created_at, updated_at
                        ) VALUES (
                            :command_id, 'building', :embedding_model,
                            'trusted_documents_without_inbox_tag', CURRENT_TIMESTAMP,
                            0, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                        )
                        RETURNING id
                        """
                    ),
                    {
                        "command_id": command_id,
                        "embedding_model": embedding_model or settings.ollama_embed_model,
                    },
                )
                .mappings()
                .one()
            )
        else:
            connection.execute(
                sql_text(
                    """
                    UPDATE embedding_index_state
                    SET status = 'building', completed_at = NULL, error = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :build_id
                    """
                ),
                {"build_id": int(existing["id"])},
            )

        connection.execute(
            sql_text(
                """
                UPDATE commands
                SET status = 'running', started_at = COALESCE(started_at, CURRENT_TIMESTAMP),
                    finished_at = NULL, error = NULL, next_retry_at = NULL,
                    active_actor_token = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = :command_id
                """
            ),
            {"command_id": command_id},
        )
        return int(existing["id"])


@activity.defn(name="archibot.prepare_embedding_generation")
async def prepare_embedding_generation(
    request: EmbeddingWorkflowRequest,
) -> PreparedEmbeddingBuild:
    if request.configuration is None:
        build_id = await asyncio.to_thread(_prepare_build_projection, request.command_id)
    else:
        build_id = await asyncio.to_thread(
            _prepare_build_projection,
            request.command_id,
            request.configuration.embedding_model,
        )
    limit = await asyncio.to_thread(_command_limit, request.command_id)
    paperless = PaperlessClient()
    try:
        documents = await _await_with_heartbeats(
            paperless.list_all_documents(limit=limit),
            {"command_id": request.command_id},
        )
    finally:
        await paperless.aclose()

    document_ids: list[int] = []
    for document in documents:
        if not is_trusted_document(document):
            continue
        if not document_embedding_text(document.title, document.content):
            continue
        document_ids.append(int(document.id))

    progress = EmbeddingProgress(
        command_id=request.command_id,
        build_id=build_id,
        total=len(document_ids),
        done=0,
        embedded=0,
        failed=0,
    )
    await asyncio.to_thread(_project_embedding_progress, progress)
    return PreparedEmbeddingBuild(request.command_id, build_id, document_ids)


@activity.defn(name="archibot.embed_document")
@serialized_model_activity
async def embed_document(request: EmbedDocumentRequest) -> EmbedDocumentResult:
    activity.heartbeat({"paperless_document_id": request.paperless_document_id})
    paperless = PaperlessClient()
    provider = None
    try:
        document = await paperless.get_document(request.paperless_document_id)
        if not is_trusted_document(document):
            return EmbedDocumentResult(request.paperless_document_id, "skipped")
        text = document_embedding_text(document.title, document.content)
        if not text:
            return EmbedDocumentResult(request.paperless_document_id, "skipped")

        configuration = request.configuration
        if configuration is None:
            provider = create_ai_provider()
        else:
            provider = create_ai_provider(
                base_url=configuration.provider_base_url,
                model=configuration.classification_model,
                provider_type=configuration.provider_type,
                embed_model=configuration.embedding_model,
                embed_num_ctx=configuration.embedding_num_ctx,
                ocr_model=configuration.ocr_text_model,
            )
        content_hash = content_hash_for_text(text)
        exists = await asyncio.to_thread(
            document_embedding_exists,
            paperless_document_id=document.id,
            content_hash=content_hash,
            embedding_model=provider.embed_model,
        )
        if exists:
            return EmbedDocumentResult(request.paperless_document_id, "embedded")

        embedding = await _await_with_heartbeats(
            provider.embed(text),
            {"paperless_document_id": request.paperless_document_id},
        )
        activity.heartbeat({"paperless_document_id": request.paperless_document_id})
        embedding_input = DocumentEmbeddingInput(
            paperless_document_id=document.id,
            title=document.title,
            content=document.content,
            embedding_model=provider.embed_model,
            embedding=embedding,
            document_date=document_date_for(document),
            metadata={
                "correspondent": document.correspondent,
                "document_type": document.document_type,
                "storage_path": document.storage_path,
                "modified": document.modified,
                "tags": document.tags,
            },
            correspondent_id=document.correspondent,
            document_type_id=document.document_type,
            storage_path_id=document.storage_path,
            tags=document.tags,
            paperless_modified=str(document.modified) if document.modified is not None else None,
            paperless_version_id=document_version_id_for(document),
            paperless_version_checksum=document_version_checksum_for(document),
            trusted_for_context=True,
        )
        stored_hash = await asyncio.to_thread(store_document_embedding, embedding_input)
        if stored_hash is None:
            raise RuntimeError(f"Embedding for Paperless document {document.id} was not persisted")
        return EmbedDocumentResult(request.paperless_document_id, "embedded")
    finally:
        if provider is not None:
            await provider.aclose()
        await paperless.aclose()


@activity.defn(name="archibot.project_embedding_progress")
async def project_embedding_progress(progress: EmbeddingProgress) -> None:
    await asyncio.to_thread(_project_embedding_progress, progress)


def _project_embedding_progress(progress: EmbeddingProgress) -> None:
    with engine().begin() as connection:
        state_update = connection.execute(
            sql_text(
                """
                UPDATE embedding_index_state
                SET document_count = :total, embedded_count = :embedded,
                    failed_count = :failed, updated_at = CURRENT_TIMESTAMP
                WHERE id = :build_id AND command_id = :command_id
                """
            ),
            {
                "total": progress.total,
                "embedded": progress.embedded,
                "failed": progress.failed,
                "build_id": progress.build_id,
                "command_id": progress.command_id,
            },
        )
        if state_update.rowcount != 1:
            raise RuntimeError(
                f"Embedding build {progress.build_id} is missing or belongs to another command"
            )

        command_update = connection.execute(
            sql_text(
                """
                UPDATE commands
                SET updated_at = CURRENT_TIMESTAMP
                WHERE id = :command_id AND status = 'running'
                """
            ),
            {"command_id": progress.command_id},
        )
        if command_update.rowcount != 1:
            raise RuntimeError(f"Embedding command {progress.command_id} is not running")


@activity.defn(name="archibot.finish_embedding_generation")
async def finish_embedding_generation(progress: EmbeddingProgress) -> EmbeddingWorkflowResult:
    return await asyncio.to_thread(_finish_embedding_generation, progress)


def _finish_embedding_generation(progress: EmbeddingProgress) -> EmbeddingWorkflowResult:
    status = "complete" if progress.failed == 0 else "failed"
    command_status = "succeeded" if progress.failed == 0 else "failed_permanent"
    error = None if progress.failed == 0 else f"{progress.failed} document embeddings failed"
    with engine().begin() as connection:
        state_update = connection.execute(
            sql_text(
                """
                UPDATE embedding_index_state
                SET status = CAST(:status AS character varying), document_count = :total,
                    embedded_count = :embedded, failed_count = :failed,
                    completed_at = CURRENT_TIMESTAMP, error = :error,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :build_id AND command_id = :command_id
                """
            ),
            {
                "status": status,
                "total": progress.total,
                "embedded": progress.embedded,
                "failed": progress.failed,
                "error": error,
                "build_id": progress.build_id,
                "command_id": progress.command_id,
            },
        )
        if state_update.rowcount != 1:
            raise RuntimeError(
                f"Embedding build {progress.build_id} is missing or belongs to another command"
            )

        command_update = connection.execute(
            sql_text(
                """
                UPDATE commands
                SET status = CAST(:status AS character varying), finished_at = CURRENT_TIMESTAMP,
                    error = :error, active_actor_token = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = :command_id
                """
            ),
            {"status": command_status, "error": error, "command_id": progress.command_id},
        )
        if command_update.rowcount != 1:
            raise RuntimeError(f"Embedding command {progress.command_id} is missing")
        if progress.failed == 0:
            _release_blocked_document_workflows(connection, progress.build_id)
    return EmbeddingWorkflowResult(
        command_id=progress.command_id,
        build_id=progress.build_id,
        total=progress.total,
        embedded=progress.embedded,
        failed=progress.failed,
        status=status,
    )


def _release_blocked_document_workflows(connection, build_id: int) -> None:
    rows = (
        connection.execute(
            sql_text(
                """
                SELECT id, temporal_workflow_id, paperless_document_id
                FROM pipeline_runs
                WHERE type = 'document'
                  AND status = 'blocked'
                  AND error_type = 'embedding_index_not_ready'
                  AND orchestration_driver = 'temporal'
                  AND temporal_workflow_id IS NOT NULL
                ORDER BY id
                FOR UPDATE
                """
            )
        )
        .mappings()
        .all()
    )
    for row in rows:
        pipeline_run_id = int(row["id"])
        workflow_id = str(row["temporal_workflow_id"])
        paperless_document_id = int(row["paperless_document_id"])
        connection.execute(
            sql_text(
                """
                UPDATE pipeline_runs
                SET status = 'queued', progress_current_phase = 'document_workflow',
                    progress_message = 'Embedding index ready; Temporal document workflow queued.',
                    progress_updated_at = CURRENT_TIMESTAMP, error_type = NULL, error = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :pipeline_run_id AND status = 'blocked'
                """
            ),
            {"pipeline_run_id": pipeline_run_id},
        )
        start_payload = json.dumps(
            {
                "pipeline_run_id": pipeline_run_id,
                "workflow_id": workflow_id,
                "paperless_document_id": paperless_document_id,
            },
            separators=(",", ":"),
        )
        signal_payload = json.dumps(
            {"pipeline_run_id": pipeline_run_id, "embedding_build_id": build_id},
            separators=(",", ":"),
        )
        connection.execute(
            sql_text(
                """
                INSERT INTO temporal_outbox_intents (
                    intent_key, operation, workflow_id, workflow_type, task_queue,
                    signal_name, payload, status, attempts, available_at,
                    created_at, updated_at
                ) VALUES (
                    CAST(:intent_key AS uuid), 'start_workflow', :workflow_id,
                    'archibot.document', :task_queue, NULL, CAST(:payload AS jsonb),
                    'pending', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                ) ON CONFLICT (intent_key) DO NOTHING
                """
            ),
            {
                "intent_key": _intent_key(f"document:{pipeline_run_id}"),
                "workflow_id": workflow_id,
                "task_queue": settings.temporal_task_queue,
                "payload": start_payload,
            },
        )
        connection.execute(
            sql_text(
                """
                INSERT INTO temporal_outbox_intents (
                    intent_key, operation, workflow_id, workflow_type, task_queue,
                    signal_name, payload, status, attempts, available_at,
                    created_at, updated_at
                ) VALUES (
                    CAST(:intent_key AS uuid), 'signal_workflow', :workflow_id,
                    NULL, NULL, 'embedding_ready', CAST(:payload AS jsonb),
                    'pending', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                ) ON CONFLICT (intent_key) DO NOTHING
                """
            ),
            {
                "intent_key": _intent_key(f"document:{pipeline_run_id}:embedding-ready:{build_id}"),
                "workflow_id": workflow_id,
                "payload": signal_payload,
            },
        )


@activity.defn(name="archibot.fail_embedding_preparation")
async def fail_embedding_preparation(failure: EmbeddingPreparationFailure) -> None:
    """Terminate projections when preparation exhausts its Temporal retries."""
    await asyncio.to_thread(_fail_embedding_preparation, failure)


def _fail_embedding_preparation(failure: EmbeddingPreparationFailure) -> None:
    with engine().begin() as connection:
        connection.execute(
            sql_text(
                """
                UPDATE embedding_index_state
                SET status = 'failed', completed_at = CURRENT_TIMESTAMP,
                    error = :error, updated_at = CURRENT_TIMESTAMP
                WHERE command_id = :command_id AND status = 'building'
                """
            ),
            {"command_id": failure.command_id, "error": failure.error},
        )
        connection.execute(
            sql_text(
                """
                UPDATE commands
                SET status = 'failed_permanent', finished_at = CURRENT_TIMESTAMP,
                    error = :error, active_actor_token = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :command_id
                  AND status IN ('pending', 'queued', 'running', 'retrying')
                """
            ),
            {"command_id": failure.command_id, "error": failure.error},
        )
