"""Idempotent per-document activities executed behind global model-phase barriers."""

from __future__ import annotations

import asyncio
import json
from collections.abc import Awaitable
from contextlib import suppress
from dataclasses import asdict
from typing import Any

from temporalio import activity

from app.ai_provider.factory import create_ai_provider
from app.clients.paperless import PaperlessClient
from app.jobs.commands import sql_text
from app.jobs.database import engine
from app.jobs.document_embeddings import (
    DocumentEmbeddingInput,
    content_hash_for_text,
    document_embedding_exists,
    document_embedding_text,
    load_document_embedding_vector,
    store_document_embedding,
)
from app.jobs.ocr_corrections import cached_ocr_correction
from app.jobs.review_suggestions import store_review_suggestion
from app.models import (
    ClassificationResult,
    PaperlessDocument,
    document_date_for,
    document_version_checksum_for,
    document_version_id_for,
)
from app.pipeline.classifier import classify
from app.pipeline.context_builder import find_similar_with_precomputed_embedding
from app.pipeline.judge import maybe_run_judge
from app.pipeline.ocr_correction import (
    cache_ocr_correction,
    maybe_correct_ocr,
    should_run_ocr_for_document,
)
from app.pipeline.trusted_context import is_trusted_document
from app.temporal.contracts import (
    DocumentPhaseRequest,
    DocumentPhaseResult,
    DocumentProcessResult,
)


def _json(value: object) -> str:
    return json.dumps(value, ensure_ascii=False, default=str, separators=(",", ":"))


def _provider(request: DocumentPhaseRequest):
    configuration = request.configuration
    return create_ai_provider(
        base_url=configuration.provider_base_url,
        model=configuration.classification_model,
        provider_type=configuration.provider_type,
        embed_model=configuration.embedding_model,
        embed_num_ctx=configuration.embedding_num_ctx,
        ocr_model=configuration.ocr_text_model,
    )


def _load_run(pipeline_run_id: int) -> dict[str, Any]:
    with engine().connect() as connection:
        row = (
            connection.execute(
                sql_text(
                    """
                    SELECT id, status, orchestration_driver, temporal_workflow_id,
                           paperless_document_id
                    FROM pipeline_runs
                    WHERE id = :pipeline_run_id AND type = 'document'
                    """
                ),
                {"pipeline_run_id": pipeline_run_id},
            )
            .mappings()
            .first()
        )
    if row is None:
        raise ValueError(f"Document pipeline run {pipeline_run_id} does not exist")
    if row["orchestration_driver"] != "temporal":
        raise ValueError(f"Document pipeline run {pipeline_run_id} is not owned by Temporal")
    if row["paperless_document_id"] is None:
        raise ValueError(f"Document pipeline run {pipeline_run_id} has no Paperless document")
    return dict(row)


_HEARTBEAT_SECONDS = 30


async def _await_with_heartbeats[T](awaitable: Awaitable[T], details: dict[str, object]) -> T:
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


def _load_state(pipeline_run_id: int, cycle: int, phase: str) -> dict[str, Any] | None:
    with engine().connect() as connection:
        row = (
            connection.execute(
                sql_text(
                    """
                    SELECT * FROM temporal_document_phase_states
                    WHERE pipeline_run_id = :pipeline_run_id
                      AND cycle = :cycle
                      AND phase = :phase
                    """
                ),
                {"pipeline_run_id": pipeline_run_id, "cycle": cycle, "phase": phase},
            )
            .mappings()
            .first()
        )
    return None if row is None else dict(row)


def _snapshot(document: PaperlessDocument) -> dict[str, Any]:
    return document.model_dump(mode="json", exclude={"content"})


def _assert_same_document_version(document: PaperlessDocument, state: dict[str, Any]) -> None:
    snapshot = state.get("document_snapshot")
    if not isinstance(snapshot, dict):
        raise RuntimeError("Document phase snapshot is missing")
    expected_checksum = snapshot.get("current_version_checksum") or snapshot.get("checksum")
    actual_checksum = document_version_checksum_for(document)
    if expected_checksum and actual_checksum and str(expected_checksum) != str(actual_checksum):
        raise ValueError("Paperless document changed during Temporal model phases")
    expected_modified = snapshot.get("modified")
    actual_modified = document.modified.isoformat() if document.modified is not None else None
    if not expected_checksum and expected_modified and actual_modified != expected_modified:
        raise ValueError("Paperless document changed during Temporal model phases")


def _phase_already_done(state: dict[str, Any] | None, configuration_revision: str) -> bool:
    return bool(
        state
        and state.get("status") in {"completed", "skipped"}
        and state.get("configuration_revision") == configuration_revision
    )


def _upsert_phase_state(
    request: DocumentPhaseRequest,
    *,
    phase: str,
    status: str,
    document_snapshot: dict[str, Any] | None = None,
    classification_result: dict[str, Any] | None = None,
    raw_response: str | None = None,
    context_document_ids: list[int] | None = None,
    judge_result: dict[str, Any] | None = None,
    judge_verdict: str | None = None,
    judge_reasoning: str | None = None,
    original_proposed_json: str | None = None,
) -> None:
    run = _load_run(request.pipeline_run_id)
    with engine().begin() as connection:
        connection.execute(
            sql_text(
                """
                INSERT INTO temporal_document_phase_states (
                    pipeline_run_id, paperless_document_id, cycle, phase, status,
                    configuration_revision, model_configuration, document_snapshot,
                    classification_result, raw_response, context_document_ids,
                    judge_result, judge_verdict, judge_reasoning,
                    original_proposed_json, error, created_at, updated_at
                ) VALUES (
                    :pipeline_run_id, :paperless_document_id, :cycle, :phase, :status,
                    :configuration_revision, CAST(:model_configuration AS jsonb),
                    CAST(:document_snapshot AS jsonb), CAST(:classification_result AS jsonb),
                    :raw_response, CAST(:context_document_ids AS jsonb),
                    CAST(:judge_result AS jsonb), :judge_verdict, :judge_reasoning,
                    :original_proposed_json, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                ON CONFLICT (pipeline_run_id, cycle, phase)
                DO UPDATE SET status = EXCLUDED.status,
                              configuration_revision = EXCLUDED.configuration_revision,
                              model_configuration = EXCLUDED.model_configuration,
                              document_snapshot = COALESCE(EXCLUDED.document_snapshot, temporal_document_phase_states.document_snapshot),
                              classification_result = COALESCE(EXCLUDED.classification_result, temporal_document_phase_states.classification_result),
                              raw_response = COALESCE(EXCLUDED.raw_response, temporal_document_phase_states.raw_response),
                              context_document_ids = COALESCE(EXCLUDED.context_document_ids, temporal_document_phase_states.context_document_ids),
                              judge_result = COALESCE(EXCLUDED.judge_result, temporal_document_phase_states.judge_result),
                              judge_verdict = COALESCE(EXCLUDED.judge_verdict, temporal_document_phase_states.judge_verdict),
                              judge_reasoning = COALESCE(EXCLUDED.judge_reasoning, temporal_document_phase_states.judge_reasoning),
                              original_proposed_json = COALESCE(EXCLUDED.original_proposed_json, temporal_document_phase_states.original_proposed_json),
                              error = NULL,
                              updated_at = CURRENT_TIMESTAMP
                """
            ),
            {
                "pipeline_run_id": request.pipeline_run_id,
                "paperless_document_id": int(run["paperless_document_id"]),
                "cycle": request.cycle,
                "phase": phase,
                "status": status,
                "configuration_revision": request.configuration.configuration_revision,
                "model_configuration": _json(asdict(request.configuration)),
                "document_snapshot": _json(document_snapshot) if document_snapshot else None,
                "classification_result": (
                    _json(classification_result) if classification_result else None
                ),
                "raw_response": raw_response,
                "context_document_ids": (
                    _json(context_document_ids) if context_document_ids is not None else None
                ),
                "judge_result": _json(judge_result) if judge_result else None,
                "judge_verdict": judge_verdict,
                "judge_reasoning": judge_reasoning,
                "original_proposed_json": original_proposed_json,
            },
        )
        connection.execute(
            sql_text(
                """
                UPDATE pipeline_runs
                SET status = 'running', started_at = COALESCE(started_at, CURRENT_TIMESTAMP),
                    progress_current_phase = :phase,
                    progress_message = :message,
                    progress_updated_at = CURRENT_TIMESTAMP,
                    error_type = NULL, error = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = :pipeline_run_id
                  AND orchestration_driver = 'temporal'
                  AND status NOT IN ('cancel_requested', 'cancelled')
                """
            ),
            {
                "pipeline_run_id": request.pipeline_run_id,
                "phase": phase,
                "message": f"Temporal {phase} phase {status}.",
            },
        )


async def _document_for(
    request: DocumentPhaseRequest,
) -> tuple[dict[str, Any], PaperlessClient, PaperlessDocument]:
    run = await asyncio.to_thread(_load_run, request.pipeline_run_id)
    paperless = PaperlessClient()
    try:
        document = await paperless.get_document(int(run["paperless_document_id"]))
    except Exception:
        await paperless.aclose()
        raise
    return run, paperless, document


@activity.defn(name="archibot.process_document_embedding_phase")
async def process_document_embedding_phase(
    request: DocumentPhaseRequest,
) -> DocumentPhaseResult:
    state = await asyncio.to_thread(
        _load_state, request.pipeline_run_id, request.cycle, "embedding"
    )
    if _phase_already_done(state, request.configuration.configuration_revision):
        return DocumentPhaseResult(request.pipeline_run_id, "embedding", str(state["status"]))
    _, paperless, document = await _document_for(request)
    provider = _provider(request)
    try:
        text = document_embedding_text(document.title, document.content)
        status = "skipped"
        if text:
            content_hash = content_hash_for_text(text)
            exists = await asyncio.to_thread(
                document_embedding_exists,
                paperless_document_id=document.id,
                content_hash=content_hash,
                embedding_model=request.configuration.embedding_model,
            )
            if not exists:
                activity.heartbeat(
                    {"pipeline_run_id": request.pipeline_run_id, "phase": "embedding"}
                )
                embedding = await _await_with_heartbeats(
                    provider.embed(text),
                    {"pipeline_run_id": request.pipeline_run_id, "phase": "embedding"},
                )
                if not embedding:
                    raise ValueError(
                        f"Paperless document {document.id} returned an empty embedding"
                    )
                stored = await asyncio.to_thread(
                    store_document_embedding,
                    DocumentEmbeddingInput(
                        paperless_document_id=document.id,
                        title=document.title,
                        content=document.content,
                        embedding_model=request.configuration.embedding_model,
                        embedding=embedding,
                        document_date=document_date_for(document),
                        correspondent_id=document.correspondent,
                        document_type_id=document.document_type,
                        storage_path_id=document.storage_path,
                        tags=document.tags,
                        paperless_modified=(
                            str(document.modified) if document.modified is not None else None
                        ),
                        paperless_version_id=document_version_id_for(document),
                        paperless_version_checksum=document_version_checksum_for(document),
                        trusted_for_context=is_trusted_document(document),
                    ),
                )
                if stored is None:
                    raise RuntimeError(
                        f"Embedding for Paperless document {document.id} was not persisted"
                    )
            status = "completed"
        await asyncio.to_thread(
            _upsert_phase_state,
            request,
            phase="embedding",
            status=status,
            document_snapshot=_snapshot(document),
        )
        return DocumentPhaseResult(request.pipeline_run_id, "embedding", status)
    finally:
        await provider.aclose()
        await paperless.aclose()


@activity.defn(name="archibot.process_document_ocr_phase")
async def process_document_ocr_phase(request: DocumentPhaseRequest) -> DocumentPhaseResult:
    state = await asyncio.to_thread(_load_state, request.pipeline_run_id, request.cycle, "ocr")
    if _phase_already_done(state, request.configuration.configuration_revision):
        return DocumentPhaseResult(request.pipeline_run_id, "ocr", str(state["status"]))
    embedding_state = await asyncio.to_thread(
        _load_state, request.pipeline_run_id, request.cycle, "embedding"
    )
    if embedding_state is None:
        raise RuntimeError("Embedding phase state is missing")
    _, paperless, document = await _document_for(request)
    provider = _provider(request)
    try:
        _assert_same_document_version(document, embedding_state)
        mode = request.configuration.ocr_mode
        status = "skipped"
        if mode != "off":
            tags = await paperless.list_tags()
            eligible, _ = should_run_ocr_for_document(document, available_tags=tags)
            if eligible:
                activity.heartbeat({"pipeline_run_id": request.pipeline_run_id, "phase": "ocr"})
                corrected, corrections = await _await_with_heartbeats(
                    maybe_correct_ocr(
                        document,
                        provider,
                        paperless,
                        mode=mode,
                        vision_model=request.configuration.ocr_vision_model,
                        num_ctx=request.configuration.ocr_num_ctx,
                    ),
                    {"pipeline_run_id": request.pipeline_run_id, "phase": "ocr"},
                )
                await asyncio.to_thread(
                    cache_ocr_correction,
                    document.id,
                    corrected,
                    mode,
                    corrections,
                )
                status = "completed"
        await asyncio.to_thread(
            _upsert_phase_state,
            request,
            phase="ocr",
            status=status,
            document_snapshot=_snapshot(document),
        )
        return DocumentPhaseResult(request.pipeline_run_id, "ocr", status)
    finally:
        await provider.aclose()
        await paperless.aclose()


async def _catalog(paperless: PaperlessClient) -> tuple[list[Any], list[Any], list[Any], list[Any]]:
    return (
        await paperless.list_correspondents(),
        await paperless.list_document_types(),
        await paperless.list_storage_paths(),
        await paperless.list_tags(),
    )


def _with_cached_ocr(
    document: PaperlessDocument, ocr_state: dict[str, Any] | None
) -> PaperlessDocument:
    if ocr_state is None or ocr_state.get("status") != "completed":
        return document
    corrected = cached_ocr_correction(document.id)
    if corrected is None:
        return document
    return document.model_copy(update={"content": corrected})


@activity.defn(name="archibot.process_document_classification_phase")
async def process_document_classification_phase(
    request: DocumentPhaseRequest,
) -> DocumentPhaseResult:
    state = await asyncio.to_thread(
        _load_state, request.pipeline_run_id, request.cycle, "classification"
    )
    if _phase_already_done(state, request.configuration.configuration_revision):
        return DocumentPhaseResult(request.pipeline_run_id, "classification", str(state["status"]))
    embedding_state = await asyncio.to_thread(
        _load_state, request.pipeline_run_id, request.cycle, "embedding"
    )
    if embedding_state is None:
        raise RuntimeError("Document preparation state is missing")
    ocr_state = await asyncio.to_thread(_load_state, request.pipeline_run_id, request.cycle, "ocr")
    _, paperless, original = await _document_for(request)
    provider = _provider(request)
    try:
        _assert_same_document_version(original, embedding_state)
        document = await asyncio.to_thread(_with_cached_ocr, original, ocr_state)
        embedding = await asyncio.to_thread(
            load_document_embedding_vector,
            document.id,
            embedding_model=request.configuration.embedding_model,
            trusted_only=False,
        )
        similar = []
        if embedding:
            similar = await find_similar_with_precomputed_embedding(
                document,
                embedding,
                paperless,
                embedding_model=request.configuration.embedding_model,
            )
        correspondents, doctypes, storage_paths, tags = await _catalog(paperless)
        activity.heartbeat({"pipeline_run_id": request.pipeline_run_id, "phase": "classification"})
        result, raw_response = await _await_with_heartbeats(
            classify(
                document,
                [item.document for item in similar],
                correspondents,
                doctypes,
                storage_paths,
                tags,
                provider,
                num_ctx=request.configuration.classification_num_ctx,
            ),
            {"pipeline_run_id": request.pipeline_run_id, "phase": "classification"},
        )
        await asyncio.to_thread(
            _upsert_phase_state,
            request,
            phase="classification",
            status="completed",
            classification_result=result.model_dump(mode="json"),
            raw_response=raw_response,
            context_document_ids=[int(item.document.id) for item in similar],
            document_snapshot=_snapshot(original),
        )
        return DocumentPhaseResult(request.pipeline_run_id, "classification", "completed")
    finally:
        await provider.aclose()
        await paperless.aclose()


async def _load_context_documents(
    paperless: PaperlessClient, state: dict[str, Any]
) -> list[PaperlessDocument]:
    raw_ids = state.get("context_document_ids")
    if not isinstance(raw_ids, list):
        return []
    documents: list[PaperlessDocument] = []
    for raw_id in raw_ids:
        try:
            candidate = await paperless.get_document(int(raw_id))
        except Exception:
            continue
        if is_trusted_document(candidate):
            documents.append(candidate)
    return documents


@activity.defn(name="archibot.process_document_judge_phase")
async def process_document_judge_phase(request: DocumentPhaseRequest) -> DocumentPhaseResult:
    state = await asyncio.to_thread(_load_state, request.pipeline_run_id, request.cycle, "judge")
    if _phase_already_done(state, request.configuration.configuration_revision):
        return DocumentPhaseResult(request.pipeline_run_id, "judge", str(state["status"]))
    classification_state = await asyncio.to_thread(
        _load_state, request.pipeline_run_id, request.cycle, "classification"
    )
    if classification_state is None or not isinstance(
        classification_state.get("classification_result"), dict
    ):
        raise RuntimeError("Classification phase result is missing")
    ocr_state = await asyncio.to_thread(_load_state, request.pipeline_run_id, request.cycle, "ocr")
    _, paperless, original = await _document_for(request)
    provider = _provider(request)
    try:
        _assert_same_document_version(original, classification_state)
        document = await asyncio.to_thread(_with_cached_ocr, original, ocr_state)
        context_documents = await _load_context_documents(paperless, classification_state)
        correspondents, doctypes, storage_paths, tags = await _catalog(paperless)
        initial = ClassificationResult.model_validate(classification_state["classification_result"])
        raw_response = str(classification_state.get("raw_response") or "{}")
        activity.heartbeat({"pipeline_run_id": request.pipeline_run_id, "phase": "judge"})
        judged = await _await_with_heartbeats(
            maybe_run_judge(
                document,
                initial,
                raw_response,
                context_documents,
                correspondents,
                doctypes,
                storage_paths,
                tags,
                provider,
                enabled=request.configuration.judge_enabled,
                confidence_threshold=request.configuration.judge_confidence_threshold,
                model=request.configuration.judge_model,
                num_ctx=request.configuration.classification_num_ctx,
            ),
            {"pipeline_run_id": request.pipeline_run_id, "phase": "judge"},
        )
        status = "skipped" if judged.verdict in {None, "skipped"} else "completed"
        await asyncio.to_thread(
            _upsert_phase_state,
            request,
            phase="judge",
            status=status,
            judge_result=judged.result.model_dump(mode="json"),
            judge_verdict=judged.verdict or "skipped",
            judge_reasoning=judged.reasoning,
            original_proposed_json=judged.original_proposed_json,
            document_snapshot=_snapshot(original),
        )
        return DocumentPhaseResult(request.pipeline_run_id, "judge", status)
    finally:
        await provider.aclose()
        await paperless.aclose()


def _finish_document_projection(pipeline_run_id: int, suggestion_id: int) -> None:
    with engine().begin() as connection:
        connection.execute(
            sql_text(
                """
                UPDATE pipeline_runs
                SET status = 'succeeded', finished_at = CURRENT_TIMESTAMP,
                    progress_current_phase = 'review_suggestion',
                    progress_total = 1, progress_done = 1,
                    progress_phase_total = 1, progress_phase_done = 1,
                    progress_message = 'Review suggestion persisted after Temporal phase release.',
                    progress_updated_at = CURRENT_TIMESTAMP,
                    error_type = NULL, error = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = :pipeline_run_id AND orchestration_driver = 'temporal'
                """
            ),
            {"pipeline_run_id": pipeline_run_id},
        )
        connection.execute(
            sql_text(
                """
                INSERT INTO pipeline_events (
                    pipeline_run_id, event_type, level, message, payload, created_at
                ) VALUES (
                    :pipeline_run_id, 'document.review_suggestion.stored', 'info',
                    'Review suggestion persisted after all model phases completed.',
                    CAST(:payload AS jsonb), CURRENT_TIMESTAMP
                )
                """
            ),
            {
                "pipeline_run_id": pipeline_run_id,
                "payload": _json({"review_suggestion_id": suggestion_id}),
            },
        )


@activity.defn(name="archibot.publish_document_review")
async def publish_document_review(request: DocumentPhaseRequest) -> DocumentProcessResult:
    classification_state = await asyncio.to_thread(
        _load_state, request.pipeline_run_id, request.cycle, "classification"
    )
    judge_state = await asyncio.to_thread(
        _load_state, request.pipeline_run_id, request.cycle, "judge"
    )
    ocr_state = await asyncio.to_thread(_load_state, request.pipeline_run_id, request.cycle, "ocr")
    if classification_state is None:
        raise RuntimeError("Document classification state is missing")
    state = judge_state or classification_state
    pipeline_run_id = request.pipeline_run_id
    run = await asyncio.to_thread(_load_run, pipeline_run_id)
    paperless = PaperlessClient()
    try:
        document = await paperless.get_document(int(run["paperless_document_id"]))
        _assert_same_document_version(document, state)
        effective_document = await asyncio.to_thread(_with_cached_ocr, document, ocr_state)
        result_payload = state.get("judge_result") or state.get("classification_result")
        if not isinstance(result_payload, dict):
            raise RuntimeError("Document model result is missing")
        result = ClassificationResult.model_validate(result_payload)
        context_documents = await _load_context_documents(paperless, classification_state)
        correspondents, doctypes, storage_paths, tags = await _catalog(paperless)
        suggestion = await asyncio.to_thread(
            store_review_suggestion,
            paperless_document_id=document.id,
            document=effective_document,
            result=result,
            raw_response=str(classification_state.get("raw_response") or "{}"),
            context_documents=context_documents,
            pipeline_run_id=pipeline_run_id,
            correspondents=correspondents,
            doctypes=doctypes,
            storage_paths=storage_paths,
            tags=tags,
            judge_verdict=(str(state["judge_verdict"]) if state.get("judge_verdict") else None),
            judge_reasoning=(
                str(state["judge_reasoning"]) if state.get("judge_reasoning") else None
            ),
            original_proposed_json=(
                str(state["original_proposed_json"])
                if state.get("original_proposed_json")
                else None
            ),
        )
        await asyncio.to_thread(_finish_document_projection, pipeline_run_id, suggestion.id)
        return DocumentProcessResult(pipeline_run_id, suggestion.id, "succeeded")
    finally:
        await paperless.aclose()
