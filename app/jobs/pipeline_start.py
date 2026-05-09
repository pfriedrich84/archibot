"""Shared pipeline-start contract for webhook, poll, manual, retry and reindex triggers."""

from __future__ import annotations

from dataclasses import dataclass

from app.events import types
from app.events.publish import publish_pipeline_event
from app.jobs.embedding_gate import ensure_embedding_index_ready
from app.jobs.idempotency import pipeline_dedupe_key
from app.jobs.pipeline_runs import upsert_document_pipeline_run


@dataclass(frozen=True)
class PipelineStartResult:
    status: str
    pipeline_run_id: int | None
    pipeline_dedupe_key: str
    blocked_reason: str | None = None


def start_or_attach_document_pipeline(
    *,
    trigger_source: str,
    paperless_document_id: int,
    paperless_modified: str | None,
    content_hash: str | None = None,
    reprocess_requested: bool = False,
    reprocess_reason: str | None = None,
    reprocess_mode: str | None = None,
) -> PipelineStartResult:
    """Start or attach to a document pipeline through the shared gate.

    This is the single helper all trigger sources must call. The first skeleton
    computes the stable dedupe key and fails closed behind the embedding gate;
    durable PostgreSQL lock/coalesce/enqueue behavior is added in the next step.
    """
    dedupe_key = pipeline_dedupe_key(
        paperless_document_id=paperless_document_id,
        paperless_modified=paperless_modified,
        content_hash=content_hash,
    )
    event_payload = {
        "trigger_source": trigger_source,
        "pipeline_dedupe_key": dedupe_key,
        "paperless_modified": paperless_modified,
        "content_hash_present": content_hash is not None,
    }
    if not ensure_embedding_index_ready():
        run = upsert_document_pipeline_run(
            trigger_source=trigger_source,
            paperless_document_id=paperless_document_id,
            paperless_modified=paperless_modified,
            content_hash=content_hash,
            pipeline_dedupe_key=dedupe_key,
            status="blocked",
            blocked_reason="embedding_index_not_ready",
            reprocess_requested=reprocess_requested,
            reprocess_reason=reprocess_reason,
            reprocess_mode=reprocess_mode,
        )
        publish_pipeline_event(
            types.PIPELINE_BLOCKED_EMBEDDING_NOT_READY,
            pipeline_run_id=run.id,
            paperless_document_id=paperless_document_id,
            level="warning",
            message="Document pipeline start blocked because the embedding index is not ready.",
            payload=event_payload,
        )
        return PipelineStartResult(
            status="blocked",
            pipeline_run_id=run.id,
            pipeline_dedupe_key=dedupe_key,
            blocked_reason="embedding_index_not_ready",
        )

    run = upsert_document_pipeline_run(
        trigger_source=trigger_source,
        paperless_document_id=paperless_document_id,
        paperless_modified=paperless_modified,
        content_hash=content_hash,
        pipeline_dedupe_key=dedupe_key,
        status="pending",
        reprocess_requested=reprocess_requested,
        reprocess_reason=reprocess_reason,
        reprocess_mode=reprocess_mode,
    )
    publish_pipeline_event(
        types.PIPELINE_START_PENDING,
        pipeline_run_id=run.id,
        paperless_document_id=paperless_document_id,
        message="Document pipeline start accepted by the shared trigger gate.",
        payload=event_payload,
    )
    return PipelineStartResult(
        status=run.status, pipeline_run_id=run.id, pipeline_dedupe_key=dedupe_key
    )
