"""Shared pipeline-start contract for webhook, poll, manual, retry and reindex triggers."""

from __future__ import annotations

import hashlib
import uuid
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
    outcome: str = "created"
    created: bool = True


def start_or_attach_document_pipeline(
    *,
    trigger_source: str,
    paperless_document_id: int,
    paperless_modified: str | None,
    content_hash: str | None = None,
    reprocess_requested: bool = False,
    reprocess_reason: str | None = None,
    reprocess_mode: str | None = None,
    force_new_run: bool = False,
    force_token: str | None = None,
    requested_by_user_id: int | None = None,
    webhook_delivery_id: int | None = None,
    command_id: int | None = None,
) -> PipelineStartResult:
    """Start or attach to a document pipeline through the shared gate.

    This is the single helper all trigger sources must call. It computes the
    stable or force-new dedupe key, fails closed behind the embedding gate, and
    uses the durable `(paperless_document_id, pipeline_dedupe_key)` constraint as
    the cross-trigger coalescing seam.
    """
    if force_new_run:
        token = force_token or str(uuid.uuid4())
        dedupe_key = force_pipeline_dedupe_key(
            paperless_document_id=paperless_document_id,
            paperless_modified=paperless_modified,
            content_hash=content_hash,
            force_token=token,
        )
    else:
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
        "force_new_run": force_new_run,
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
            webhook_delivery_id=webhook_delivery_id,
            command_id=command_id,
            requested_by_user_id=requested_by_user_id,
        )
        publish_pipeline_event(
            types.PIPELINE_BLOCKED_EMBEDDING_NOT_READY,
            pipeline_run_id=run.id,
            paperless_document_id=paperless_document_id,
            level="warning",
            message="Document pipeline start blocked because the embedding index is not ready.",
            payload={**event_payload, "outcome": "blocked" if run.created else "coalesced"},
        )
        return PipelineStartResult(
            status="blocked",
            pipeline_run_id=run.id,
            pipeline_dedupe_key=dedupe_key,
            blocked_reason="embedding_index_not_ready",
            outcome="blocked" if run.created else "coalesced",
            created=run.created,
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
        webhook_delivery_id=webhook_delivery_id,
        command_id=command_id,
        requested_by_user_id=requested_by_user_id,
    )
    outcome = _start_outcome(run_created=run.created, force_new_run=force_new_run)
    event_type = _event_type_for_outcome(outcome)
    publish_pipeline_event(
        event_type,
        pipeline_run_id=run.id,
        paperless_document_id=paperless_document_id,
        message=_message_for_outcome(outcome),
        payload={**event_payload, "outcome": outcome},
    )
    if force_new_run:
        publish_pipeline_event(
            types.PIPELINE_FORCE_REPROCESS_REQUESTED,
            pipeline_run_id=run.id,
            paperless_document_id=paperless_document_id,
            message="Manual force reprocess requested a new document pipeline run.",
            payload={**event_payload, "outcome": outcome},
        )
    return PipelineStartResult(
        status=run.status,
        pipeline_run_id=run.id,
        pipeline_dedupe_key=dedupe_key,
        outcome=outcome,
        created=run.created,
    )


def force_pipeline_dedupe_key(
    *,
    paperless_document_id: int,
    paperless_modified: str | None,
    content_hash: str | None = None,
    force_token: str,
    pipeline_version: str = "v1",
) -> str:
    raw = ":".join(
        [
            "force",
            str(paperless_document_id),
            paperless_modified or "unknown_modified",
            content_hash or "unknown_content",
            force_token,
            pipeline_version,
        ]
    )
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def _start_outcome(*, run_created: bool, force_new_run: bool) -> str:
    if run_created and force_new_run:
        return "force_created"
    if run_created:
        return "created"
    return "coalesced"


def _event_type_for_outcome(outcome: str) -> str:
    if outcome == "coalesced":
        return types.PIPELINE_START_COALESCED
    if outcome == "attached":
        return types.PIPELINE_START_ATTACHED
    return types.PIPELINE_START_PENDING


def _message_for_outcome(outcome: str) -> str:
    if outcome == "coalesced":
        return "Document pipeline start coalesced with an existing run."
    if outcome == "attached":
        return "Document pipeline start attached to an existing run."
    if outcome == "force_created":
        return "Manual force reprocess accepted as a new document pipeline run."
    return "Document pipeline start accepted by the shared trigger gate."
