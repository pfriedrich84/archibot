"""Public document processing seam for ArchiBot's classification pipeline.

This module names the domain use case explicitly: turning a Paperless inbox
Document into a stored classification suggestion, optionally auto-committed.

The implementation owns the one-document processing flow. ``app.worker`` keeps
compatibility wrappers around older private function names while it shrinks
toward a scheduling adapter only.
"""

from __future__ import annotations

import json
import time
import uuid
from datetime import UTC, datetime

import structlog

from app import db
from app.config import settings
from app.job_events import record_event
from app.models import (
    ClassificationResult,
    JudgeVerdict,
    PaperlessDocument,
    PaperlessEntity,
    ReviewDecision,
    SuggestionRow,
)
from app.pipeline import classifier, context_builder
from app.pipeline.committer import commit_suggestion
from app.pipeline.context_builder import SimilarDocument
from app.pipeline.ocr_correction import (
    _text_looks_broken,
    cache_ocr_correction,
    effective_ocr_mode,
    maybe_correct_ocr,
    should_run_ocr_for_document,
)
from app.pipeline.ports import DocumentRepository, LlmGateway
from app.pipeline.processing_models import (
    BatchProcessResult,
    ClassificationDraft,
    EmbeddingResult,
    JudgedDraft,
    JudgeOutcome,
    ProcessResult,
    StoredSuggestionResult,
)
from app.telegram_handler import notify_suggestion

log = structlog.get_logger(__name__)


def should_skip_document(doc: PaperlessDocument) -> bool:
    """Return True if the Dokument was already processed at its current version."""
    with db.get_conn() as conn:
        row = conn.execute(
            "SELECT last_updated_at, status FROM processed_documents WHERE document_id = ?",
            (doc.id,),
        ).fetchone()
    if not row:
        return False
    stored_ts = row["last_updated_at"]
    doc_ts = (doc.modified or datetime.now(tz=UTC)).isoformat()
    if stored_ts == doc_ts and row["status"] != "error":
        log.debug("document already processed", doc_id=doc.id, status=row["status"])
        return True
    return False


def mark_document_pending(doc: PaperlessDocument) -> None:
    """Mark a Dokument as pending in the processing status table."""
    with db.get_conn() as conn:
        conn.execute(
            """
            INSERT OR REPLACE INTO processed_documents
                (document_id, last_updated_at, last_processed, status)
            VALUES (?, ?, datetime('now'), 'pending')
            """,
            (doc.id, (doc.modified or datetime.now(tz=UTC)).isoformat()),
        )


def record_processing_error(stage: str, doc_id: int | None, exc: Exception) -> None:
    """Persist a processing error and mark the Dokument error when applicable."""
    try:
        with db.get_conn() as conn:
            conn.execute(
                "INSERT INTO errors (stage, document_id, message) VALUES (?, ?, ?)",
                (stage, doc_id, str(exc)),
            )
            if doc_id is not None:
                conn.execute(
                    """
                    UPDATE processed_documents SET status = 'error'
                    WHERE document_id = ?
                    """,
                    (doc_id,),
                )
    except Exception as inner:
        log.error("failed to record error", error=str(inner))


async def process_document(
    doc: PaperlessDocument,
    paperless: DocumentRepository,
    ollama: LlmGateway,
    correspondents: list[PaperlessEntity],
    doctypes: list[PaperlessEntity],
    storage_paths: list[PaperlessEntity],
    tags: list[PaperlessEntity],
    *,
    require_ocr_tag_info: bool = False,
) -> ProcessResult:
    """Run the full classification flow for one Dokument.

    This is the public Interface for one-off Dokument processing used by
    webhook, CLI, MCP, and future adapters. It hides idempotency, OCR,
    context search, classification, Vorschlag storage, notification,
    auto-commit, and embedding update ordering behind one call.
    """
    if should_skip_document(doc):
        return "skipped"

    log.info("processing document", doc_id=doc.id, title=doc.title[:80])
    mark_document_pending(doc)

    ocr_mode = effective_ocr_mode()
    eligible, reason = should_run_ocr_for_document(
        doc, available_tags=tags, require_tag_info=require_ocr_tag_info
    )
    if eligible:
        text, num_corrections = await maybe_correct_ocr(doc, ollama, paperless)
        if num_corrections > 0:
            doc = doc.model_copy(update={"content": text})
            cache_ocr_correction(doc.id, text, ocr_mode, num_corrections)
    else:
        log.debug("ocr skipped by requested tag filter", doc_id=doc.id, reason=reason)

    embedding: list[float] | None = None
    similar_results: list[SimilarDocument] = []
    summary = context_builder.document_summary(doc)
    if summary.strip():
        try:
            embedding = await ollama.embed(summary)
            similar_results = await context_builder.find_similar_with_precomputed_embedding(
                doc, embedding, paperless
            )
        except Exception as exc:
            log.warning("embedding failed", doc_id=doc.id, error=str(exc))

    context_docs = [item.document for item in similar_results]
    initial_result, raw_response = await classifier.classify(
        doc,
        context_docs,
        correspondents,
        doctypes,
        storage_paths,
        tags,
        ollama,
    )

    judge = await maybe_run_judge(
        doc,
        initial_result,
        raw_response,
        context_docs,
        correspondents,
        doctypes,
        storage_paths,
        tags,
        ollama,
        cycle_id=None,
    )
    result = judge.result

    suggestion = store_suggestion(
        doc,
        result,
        raw_response,
        correspondents,
        doctypes,
        storage_paths,
        tags,
        similar_results=similar_results,
        judge_verdict=judge.verdict,
        judge_reasoning=judge.reasoning,
        original_proposed_json=judge.original_proposed_json,
    )

    will_auto_commit = (
        settings.auto_commit_confidence > 0 and result.confidence >= settings.auto_commit_confidence
    )
    if not will_auto_commit:
        await notify_suggestion(suggestion)

    if will_auto_commit:
        log.info("auto-committing", doc_id=doc.id, confidence=result.confidence)
        tag_ids = [
            tag_id for tag in result.tags if (tag_id := resolve_entity(tag.name, tags)) is not None
        ]
        decision = ReviewDecision(
            suggestion_id=suggestion.id,
            title=result.title,
            date=suggestion.effective_date,
            correspondent_id=suggestion.effective_correspondent_id,
            doctype_id=suggestion.effective_doctype_id,
            storage_path_id=suggestion.effective_storage_path_id,
            tag_ids=tag_ids,
            action="accept",
        )
        await commit_suggestion(suggestion, decision, paperless)

    if embedding is not None:
        try:
            context_builder.store_embedding(doc, embedding)
        except Exception as exc:
            log.warning("indexing failed", doc_id=doc.id, error=str(exc))

    return "auto_committed" if will_auto_commit else "classified"


async def maybe_run_judge(
    doc: PaperlessDocument,
    initial: ClassificationResult,
    raw_response: str,
    context_docs: list[PaperlessDocument],
    correspondents: list[PaperlessEntity],
    doctypes: list[PaperlessEntity],
    storage_paths: list[PaperlessEntity],
    tags: list[PaperlessEntity],
    ollama: LlmGateway,
    *,
    cycle_id: str | None,
) -> JudgeOutcome:
    """Gate + run Judge verification, returning the possibly corrected result."""
    if getattr(settings, "enable_judge_verification", False) is not True:
        return JudgeOutcome(result=initial)
    threshold = getattr(settings, "judge_confidence_threshold", 0)
    if not isinstance(threshold, int | float):
        threshold = 0
    if initial.confidence >= threshold:
        return JudgeOutcome(result=initial, verdict="skipped")
    if not context_docs:
        return JudgeOutcome(result=initial, verdict="skipped")

    t0 = time.monotonic()
    try:
        verdict: JudgeVerdict = await classifier.verify(
            doc,
            context_docs,
            initial,
            correspondents,
            doctypes,
            storage_paths,
            tags,
            ollama,
        )
    except Exception as exc:
        log.warning("judge verification raised", doc_id=doc.id, error=str(exc))
        if cycle_id is not None:
            record_phase_timing(cycle_id, doc.id, "judge", t0, success=False)
        return JudgeOutcome(result=initial, verdict="error", reasoning=str(exc)[:300])

    success = verdict.verdict in ("agree", "corrected")
    if cycle_id is not None:
        record_phase_timing(cycle_id, doc.id, "judge", t0, success=success)

    if verdict.verdict == "corrected" and verdict.corrected is not None:
        log.info("judge corrected classification", doc_id=doc.id)
        return JudgeOutcome(
            result=verdict.corrected,
            verdict="corrected",
            reasoning=verdict.reasoning or None,
            original_proposed_json=raw_response,
        )

    return JudgeOutcome(
        result=initial,
        verdict=verdict.verdict,
        reasoning=verdict.reasoning or None,
    )


def record_phase_timing(
    cycle_id: str,
    doc_id: int,
    phase: str,
    start_mono: float,
    *,
    success: bool = True,
) -> None:
    """Record phase timing for a single Dokument."""
    duration_ms = int((time.monotonic() - start_mono) * 1000)
    now = datetime.now(tz=UTC).isoformat()
    try:
        with db.get_conn() as conn:
            conn.execute(
                """INSERT INTO phase_timing
                   (poll_cycle_id, document_id, phase, started_at, finished_at, duration_ms, success)
                   VALUES (?, ?, ?, ?, ?, ?, ?)""",
                (cycle_id, doc_id, phase, now, now, duration_ms, 1 if success else 0),
            )
    except Exception as exc:
        log.warning("failed to record timing", doc_id=doc_id, phase=phase, error=str(exc))


# ---------------------------------------------------------------------------
# Phased pipeline for batched processing
# ---------------------------------------------------------------------------
def _set_poll_phase_progress(phase: str, total: int) -> None:
    """Update the user-facing per-phase progress counters for poll jobs."""
    from app import worker

    worker._poll_progress.phase = phase
    worker._poll_progress.phase_total = total
    worker._poll_progress.phase_done = 0


def _advance_poll_phase_progress() -> None:
    """Advance the current user-facing per-phase counter by one item."""
    from app import worker

    if worker._poll_progress.running:
        worker._poll_progress.phase_done += 1


async def phase_ocr(
    docs: list[PaperlessDocument],
    ollama: LlmGateway,
    paperless: DocumentRepository,
    cycle_id: str,
    tags: list[PaperlessEntity] | None = None,
) -> list[PaperlessDocument]:
    """Phase 1: Run OCR correction on all documents.

    Returns updated document list (content modified in-memory where needed).
    Corrected text is cached in ``doc_ocr_cache`` (never sent to Paperless).
    """
    from app import worker

    ocr_mode = effective_ocr_mode()
    if ocr_mode == "off":
        worker._poll_progress.phase_done = len(docs)
        return docs

    log.info("phase ocr started", count=len(docs), mode=ocr_mode)
    job_id = getattr(worker._poll_progress, "job_id", None)
    job_type = getattr(worker._poll_progress, "job_type", None) or "poll"
    corrected: list[PaperlessDocument] = []
    for doc in docs:
        if worker._poll_progress.cancelled:
            log.info("poll cancelled during OCR phase")
            corrected.append(doc)
            continue
        t0 = time.monotonic()
        ok = True
        try:
            eligible, reason = should_run_ocr_for_document(doc, available_tags=tags)
            if not eligible:
                log.debug("ocr skipped by requested tag filter", doc_id=doc.id, reason=reason)
                record_event(
                    job_id,
                    job_type,
                    "ocr_skipped",
                    f"Dokument #{doc.id}: OCR übersprungen.",
                    phase="ocr",
                    document_id=doc.id,
                    data={"reason": reason},
                )
                corrected.append(doc)
                _advance_poll_phase_progress()
                continue

            if ocr_mode != "vision_full" and not _text_looks_broken(doc.content or ""):
                record_event(
                    job_id,
                    job_type,
                    "ocr_skipped_clean",
                    f"Dokument #{doc.id}: OCR nicht nötig.",
                    phase="ocr",
                    document_id=doc.id,
                    data={"reason": "text_clean"},
                )
                corrected.append(doc)
                _advance_poll_phase_progress()
                continue

            text, num_corrections = await maybe_correct_ocr(doc, ollama, paperless)
            if num_corrections > 0:
                doc = doc.model_copy(update={"content": text})
                cache_ocr_correction(doc.id, text, ocr_mode, num_corrections)
                record_event(
                    job_id,
                    job_type,
                    "ocr_corrected",
                    f"Dokument #{doc.id}: OCR korrigiert ({num_corrections} Korrekturen).",
                    phase="ocr",
                    level="success",
                    document_id=doc.id,
                    data={"corrections": num_corrections},
                )
            else:
                record_event(
                    job_id,
                    job_type,
                    "ocr_done",
                    f"Dokument #{doc.id}: OCR geprüft.",
                    phase="ocr",
                    level="success",
                    document_id=doc.id,
                )
        except Exception as exc:
            ok = False
            record_event(
                job_id,
                job_type,
                "ocr_failed",
                f"Dokument #{doc.id}: OCR fehlgeschlagen.",
                phase="ocr",
                level="warning",
                document_id=doc.id,
                data={"error": str(exc)[:300]},
            )
            log.warning("ocr correction failed", doc_id=doc.id, error=str(exc))
        record_phase_timing(cycle_id, doc.id, "ocr", t0, success=ok)
        corrected.append(doc)
        _advance_poll_phase_progress()

    # Unload the OCR model from VRAM if we used a separate one
    if ocr_mode == "text":
        await ollama.unload_model(ollama.ocr_model, swap=True)
    return corrected


async def phase_embed(
    docs: list[PaperlessDocument],
    paperless: DocumentRepository,
    ollama: LlmGateway,
    cycle_id: str,
) -> dict[int, EmbeddingResult]:
    """Phase 2: Compute embeddings and find similar documents (embed model).

    Returns a dict mapping ``doc.id`` to its embedding + similar documents.
    Each document's embedding is computed exactly once and reused for indexing.
    """
    from app import worker

    log.info("phase embed started", count=len(docs))
    job_id = getattr(worker._poll_progress, "job_id", None)
    job_type = getattr(worker._poll_progress, "job_type", None) or "poll"
    results: dict[int, EmbeddingResult] = {}

    for doc in docs:
        if worker._poll_progress.cancelled:
            log.info("poll cancelled during embed phase")
            break
        t0 = time.monotonic()
        er = EmbeddingResult()
        ok = True
        summary = context_builder.document_summary(doc)
        if summary.strip():
            try:
                vec = await ollama.embed(summary)
                er.embedding = vec
                er.similar_results = await context_builder.find_similar_with_precomputed_embedding(
                    doc, vec, paperless
                )
                record_event(
                    job_id,
                    job_type,
                    "embed_done",
                    f"Dokument #{doc.id}: Embedding erstellt ({len(er.similar_results)} ähnliche Dokumente).",
                    phase="embed",
                    level="success",
                    document_id=doc.id,
                    data={"similar": len(er.similar_results)},
                )
            except Exception as exc:
                ok = False
                record_event(
                    job_id,
                    job_type,
                    "embed_failed",
                    f"Dokument #{doc.id}: Embedding fehlgeschlagen.",
                    phase="embed",
                    level="warning",
                    document_id=doc.id,
                    data={"error": str(exc)[:300]},
                )
                log.warning("embedding failed", doc_id=doc.id, error=str(exc))
        record_phase_timing(cycle_id, doc.id, "embed", t0, success=ok)
        results[doc.id] = er
        _advance_poll_phase_progress()

    await ollama.unload_model(ollama.embed_model, swap=True)
    return results


async def phase_classify_only(
    docs: list[PaperlessDocument],
    embed_results: dict[int, EmbeddingResult],
    ollama: LlmGateway,
    correspondents: list[PaperlessEntity],
    doctypes: list[PaperlessEntity],
    storage_paths: list[PaperlessEntity],
    tags: list[PaperlessEntity],
    cycle_id: str,
) -> list[ClassificationDraft]:
    """Phase 3: classify all documents with the classifier model only."""
    from app import worker

    log.info("phase classify started", count=len(docs))
    job_id = getattr(worker._poll_progress, "job_id", None)
    job_type = getattr(worker._poll_progress, "job_type", None) or "poll"
    drafts: list[ClassificationDraft] = []

    for doc in docs:
        if worker._poll_progress.cancelled:
            log.info("poll cancelled during classify phase")
            break
        er = embed_results.get(doc.id, EmbeddingResult())
        context_docs = [r.document for r in er.similar_results]
        record_event(
            job_id,
            job_type,
            "classify_started",
            f"Dokument #{doc.id}: Klassifizierung gestartet.",
            phase="classify",
            document_id=doc.id,
        )
        t0 = time.monotonic()
        try:
            initial_result, raw_response = await classifier.classify(
                doc,
                context_docs,
                correspondents,
                doctypes,
                storage_paths,
                tags,
                ollama,
            )
            record_phase_timing(cycle_id, doc.id, "classify", t0, success=True)
            record_event(
                job_id,
                job_type,
                "classify_done",
                f"Dokument #{doc.id}: klassifiziert ({initial_result.confidence}% Sicherheit).",
                phase="classify",
                level="success",
                document_id=doc.id,
                data={"confidence": initial_result.confidence},
            )
            drafts.append(
                ClassificationDraft(
                    doc, context_docs, er.similar_results, initial_result, raw_response
                )
            )
        except Exception as exc:
            record_phase_timing(cycle_id, doc.id, "classify", t0, success=False)
            record_event(
                job_id,
                job_type,
                "classify_failed",
                f"Dokument #{doc.id}: Klassifizierung fehlgeschlagen.",
                phase="classify",
                level="error",
                document_id=doc.id,
                data={"error": str(exc)[:300]},
            )
            log.error("classification failed", doc_id=doc.id, error=repr(exc))
            worker._write_error("classify", doc.id, exc)
            drafts.append(
                ClassificationDraft(doc, context_docs, er.similar_results, error=str(exc)[:300])
            )
        _advance_poll_phase_progress()

    await ollama.unload_model(ollama.model, swap=True)
    return drafts


async def phase_judge(
    drafts: list[ClassificationDraft],
    ollama: LlmGateway,
    correspondents: list[PaperlessEntity],
    doctypes: list[PaperlessEntity],
    storage_paths: list[PaperlessEntity],
    tags: list[PaperlessEntity],
    cycle_id: str,
) -> list[JudgedDraft]:
    """Phase 4: verify all successful classifier drafts with the judge model."""
    from app import worker

    log.info("phase judge started", count=len(drafts))
    job_id = getattr(worker._poll_progress, "job_id", None)
    job_type = getattr(worker._poll_progress, "job_type", None) or "poll"
    judged: list[JudgedDraft] = []

    for draft in drafts:
        doc = draft.document
        if draft.error or draft.initial_result is None or draft.raw_response is None:
            judged.append(
                JudgedDraft(
                    doc,
                    draft.context_docs,
                    draft.similar_results,
                    error=draft.error or "classification missing",
                )
            )
            _advance_poll_phase_progress()
            continue
        if worker._poll_progress.cancelled:
            log.info("poll cancelled during judge phase")
            break
        record_event(
            job_id,
            job_type,
            "judge_started",
            f"Dokument #{doc.id}: Judge-Prüfung gestartet.",
            phase="judge",
            document_id=doc.id,
        )
        judge = await maybe_run_judge(
            doc,
            draft.initial_result,
            draft.raw_response,
            draft.context_docs,
            correspondents,
            doctypes,
            storage_paths,
            tags,
            ollama,
            cycle_id=cycle_id,
        )
        level = "warning" if judge.verdict == "error" else "success"
        event = (
            "judge_corrected"
            if judge.verdict == "corrected"
            else "judge_skipped"
            if judge.verdict == "skipped"
            else "judge_agreed"
            if judge.verdict == "agree"
            else "judge_failed"
            if judge.verdict == "error"
            else "judge_done"
        )
        record_event(
            job_id,
            job_type,
            event,
            f"Dokument #{doc.id}: Judge-Prüfung abgeschlossen ({judge.verdict or 'nicht aktiv'}).",
            phase="judge",
            level=level,
            document_id=doc.id,
            data={"verdict": judge.verdict},
        )
        judged.append(
            JudgedDraft(
                doc,
                draft.context_docs,
                draft.similar_results,
                draft.initial_result,
                draft.raw_response,
                judge,
            )
        )
        _advance_poll_phase_progress()

    return judged


async def phase_store_suggestions(
    judged: list[JudgedDraft],
    correspondents: list[PaperlessEntity],
    doctypes: list[PaperlessEntity],
    storage_paths: list[PaperlessEntity],
    tags: list[PaperlessEntity],
) -> list[StoredSuggestionResult]:
    """Phase 5: persist all judged suggestions, without notifying or committing."""
    from app import worker

    job_id = getattr(worker._poll_progress, "job_id", None)
    job_type = getattr(worker._poll_progress, "job_type", None) or "poll"
    stored: list[StoredSuggestionResult] = []
    for draft in judged:
        doc = draft.document
        if draft.error or draft.judge is None or draft.raw_response is None:
            stored.append(StoredSuggestionResult(doc, error=draft.error or "judge missing"))
            _advance_poll_phase_progress()
            continue
        try:
            result = draft.judge.result
            suggestion = store_suggestion(
                doc,
                result,
                draft.raw_response,
                correspondents,
                doctypes,
                storage_paths,
                tags,
                similar_results=draft.similar_results,
                judge_verdict=draft.judge.verdict,
                judge_reasoning=draft.judge.reasoning,
                original_proposed_json=draft.judge.original_proposed_json,
            )
            will_auto_commit = (
                settings.auto_commit_confidence > 0
                and result.confidence >= settings.auto_commit_confidence
            )
            record_event(
                job_id,
                job_type,
                "suggestion_stored",
                f"Dokument #{doc.id}: Vorschlag #{suggestion.id} gespeichert ({result.confidence}% Sicherheit).",
                phase="store",
                level="success",
                document_id=doc.id,
                data={
                    "suggestion_id": suggestion.id,
                    "confidence": result.confidence,
                    "judge": draft.judge.verdict,
                },
            )
            stored.append(StoredSuggestionResult(doc, suggestion, result, will_auto_commit))
        except Exception as exc:
            record_event(
                job_id,
                job_type,
                "store_failed",
                f"Dokument #{doc.id}: Vorschlag konnte nicht gespeichert werden.",
                phase="store",
                level="error",
                document_id=doc.id,
                data={"error": str(exc)[:300]},
            )
            log.error("suggestion storage failed", doc_id=doc.id, error=repr(exc))
            worker._write_error("store", doc.id, exc)
            stored.append(StoredSuggestionResult(doc, error=str(exc)[:300]))
        finally:
            _advance_poll_phase_progress()
    return stored


async def phase_postprocess_suggestions(
    stored: list[StoredSuggestionResult],
    paperless: DocumentRepository,
    tags: list[PaperlessEntity],
) -> tuple[int, int, int]:
    """Phase 6: notify or auto-commit stored suggestions. No LLM calls here."""
    from app import worker

    job_id = getattr(worker._poll_progress, "job_id", None)
    job_type = getattr(worker._poll_progress, "job_type", None) or "poll"
    classified = 0
    auto_committed = 0
    errored = 0

    for item in stored:
        doc = item.document
        try:
            if item.error or item.suggestion is None or item.result is None:
                raise RuntimeError(item.error or "suggestion missing")
            if item.will_auto_commit:
                record_event(
                    job_id,
                    job_type,
                    "auto_commit_started",
                    f"Dokument #{doc.id}: Auto-Commit gestartet.",
                    phase="postprocess",
                    document_id=doc.id,
                    data={"confidence": item.result.confidence},
                )
                log.info("auto-committing", doc_id=doc.id, confidence=item.result.confidence)
                tag_ids = [
                    tid
                    for t in item.result.tags
                    if (tid := resolve_entity(t.name, tags)) is not None
                ]
                decision = ReviewDecision(
                    suggestion_id=item.suggestion.id,
                    title=item.result.title,
                    date=item.suggestion.effective_date,
                    correspondent_id=item.suggestion.effective_correspondent_id,
                    doctype_id=item.suggestion.effective_doctype_id,
                    storage_path_id=item.suggestion.effective_storage_path_id,
                    tag_ids=tag_ids,
                    action="accept",
                )
                await commit_suggestion(item.suggestion, decision, paperless)
                record_event(
                    job_id,
                    job_type,
                    "auto_committed",
                    f"Dokument #{doc.id}: automatisch übernommen.",
                    phase="postprocess",
                    level="success",
                    document_id=doc.id,
                    data={"suggestion_id": item.suggestion.id},
                )
                auto_committed += 1
            else:
                await notify_suggestion(item.suggestion)
                record_event(
                    job_id,
                    job_type,
                    "pending_review",
                    f"Dokument #{doc.id}: Vorschlag wartet auf Review.",
                    phase="postprocess",
                    level="success",
                    document_id=doc.id,
                    data={"suggestion_id": item.suggestion.id},
                )
                classified += 1
            worker._poll_progress.succeeded += 1
            record_event(
                job_id,
                job_type,
                "document_done",
                f"Dokument #{doc.id}: Prüfung abgeschlossen.",
                phase="postprocess",
                level="success",
                document_id=doc.id,
            )
        except Exception as exc:
            errored += 1
            worker._poll_progress.failed += 1
            phase = "postprocess" if item.suggestion is not None else "classify"
            record_event(
                job_id,
                job_type,
                "document_failed",
                f"Dokument #{doc.id}: Prüfung fehlgeschlagen.",
                phase=phase,
                level="error",
                document_id=doc.id,
                data={"error": str(exc)[:300]},
            )
            log.error("document post-processing failed", doc_id=doc.id, error=repr(exc))
            worker._write_error(phase, doc.id, exc)
        finally:
            worker._poll_progress.done += 1
            _advance_poll_phase_progress()
    return classified, auto_committed, errored


async def phase_classify(
    docs: list[PaperlessDocument],
    embed_results: dict[int, EmbeddingResult],
    paperless: DocumentRepository,
    ollama: LlmGateway,
    correspondents: list[PaperlessEntity],
    doctypes: list[PaperlessEntity],
    storage_paths: list[PaperlessEntity],
    tags: list[PaperlessEntity],
    cycle_id: str,
) -> tuple[int, int, int]:
    """Backward-compatible classification entry point.

    Internally runs the fully split classify -> judge -> store -> postprocess ->
    embedding-store phases. New orchestration should call the split phases
    directly so GUI progress can expose each phase separately.
    """
    from app import worker

    job_id = getattr(worker._poll_progress, "job_id", None)
    job_type = getattr(worker._poll_progress, "job_type", None) or "poll"
    if not isinstance(embed_results, dict):
        embed_results = {}

    drafts = await phase_classify_only(
        docs,
        embed_results,
        ollama,
        correspondents,
        doctypes,
        storage_paths,
        tags,
        cycle_id,
    )
    record_event(
        job_id,
        job_type,
        "phase_finished",
        "Klassifizierung abgeschlossen.",
        phase="classify",
        level="success",
    )

    _set_poll_phase_progress("judge", len(drafts))
    record_event(
        job_id,
        job_type,
        "phase_started",
        "Judge-Prüfung gestartet.",
        phase="judge",
        data={"documents": len(drafts)},
    )
    judged = await phase_judge(
        drafts, ollama, correspondents, doctypes, storage_paths, tags, cycle_id
    )
    record_event(
        job_id,
        job_type,
        "phase_finished",
        "Judge-Prüfung abgeschlossen.",
        phase="judge",
        level="success",
    )

    _set_poll_phase_progress("store", len(judged))
    record_event(
        job_id,
        job_type,
        "phase_started",
        "Vorschläge werden gespeichert.",
        phase="store",
        data={"documents": len(judged)},
    )
    stored = await phase_store_suggestions(judged, correspondents, doctypes, storage_paths, tags)
    record_event(
        job_id,
        job_type,
        "phase_finished",
        "Vorschläge gespeichert.",
        phase="store",
        level="success",
    )

    _set_poll_phase_progress("postprocess", len(stored))
    record_event(
        job_id,
        job_type,
        "phase_started",
        "Nachverarbeitung gestartet.",
        phase="postprocess",
        data={"documents": len(stored)},
    )
    counts = await phase_postprocess_suggestions(stored, paperless, tags)
    record_event(
        job_id,
        job_type,
        "phase_finished",
        "Nachverarbeitung abgeschlossen.",
        phase="postprocess",
        level="success",
    )

    _set_poll_phase_progress("finalize", len(docs))
    record_event(
        job_id,
        job_type,
        "phase_started",
        "Finalisierung gestartet.",
        phase="finalize",
        data={"documents": len(docs)},
    )
    phase_store_embeddings(docs, embed_results)
    record_event(
        job_id,
        job_type,
        "phase_finished",
        "Finalisierung abgeschlossen.",
        phase="finalize",
        level="success",
    )
    return counts


def phase_store_embeddings(
    docs: list[PaperlessDocument],
    embed_results: dict[int, EmbeddingResult],
) -> None:
    """Phase 7: index all successfully precomputed embeddings."""
    from app import worker

    job_id = getattr(worker._poll_progress, "job_id", None)
    job_type = getattr(worker._poll_progress, "job_type", None) or "poll"
    for doc in docs:
        er = embed_results.get(doc.id)
        if er is None or er.embedding is None:
            _advance_poll_phase_progress()
            continue
        try:
            context_builder.store_embedding(doc, er.embedding)
            record_event(
                job_id,
                job_type,
                "embedding_stored",
                f"Dokument #{doc.id}: Embedding gespeichert.",
                phase="finalize",
                level="success",
                document_id=doc.id,
            )
        except Exception as exc:
            record_event(
                job_id,
                job_type,
                "embedding_store_failed",
                f"Dokument #{doc.id}: Embedding konnte nicht gespeichert werden.",
                phase="finalize",
                level="warning",
                document_id=doc.id,
                data={"error": str(exc)[:300]},
            )
            log.warning("indexing failed", doc_id=doc.id, error=str(exc))
        finally:
            _advance_poll_phase_progress()


async def process_batch(
    docs: list[PaperlessDocument],
    paperless: DocumentRepository,
    ollama: LlmGateway,
    correspondents: list[PaperlessEntity],
    doctypes: list[PaperlessEntity],
    storage_paths: list[PaperlessEntity],
    tags: list[PaperlessEntity],
    *,
    force: bool = False,
    progress: object | None = None,
) -> BatchProcessResult:
    """Run the batched Posteingang processing flow over fetched Dokumente.

    Fetching inbox Dokumente and entity lists remains the worker adapter's job.
    This module owns idempotency filtering, pending status, poll-cycle records,
    phase ordering, and processing counters.
    """
    job_id = getattr(progress, "job_id", None)
    job_type = getattr(progress, "job_type", None) or "poll"
    batch: list[PaperlessDocument] = []
    skipped = 0
    if progress is not None:
        _set_poll_phase_progress("prepare", len(docs))
    for doc in docs:
        if not force and should_skip_document(doc):
            skipped += 1
            record_event(
                job_id,
                job_type,
                "document_skipped",
                f"Dokument #{doc.id} übersprungen - unverändert.",
                phase="prepare",
                document_id=doc.id,
                data={"title": doc.title},
            )
            _advance_poll_phase_progress()
            continue
        log.info("processing document", doc_id=doc.id, title=doc.title[:80])
        record_event(
            job_id,
            job_type,
            "document_started",
            f"Dokument #{doc.id} wird geprüft.",
            phase="prepare",
            document_id=doc.id,
            data={"title": doc.title},
        )
        mark_document_pending(doc)
        batch.append(doc)
        _advance_poll_phase_progress()

    if progress is not None:
        progress.total = len(batch)
        progress.skipped = skipped

    if not batch:
        record_event(
            job_id,
            job_type,
            "job_noop",
            "Keine neuen oder geänderten Dokumente zu prüfen.",
            phase="prepare",
            level="success",
            data={"skipped": skipped},
        )
        return BatchProcessResult(total=len(docs), skipped=skipped)

    cycle_id = uuid.uuid4().hex[:16]
    if progress is not None:
        progress.cycle_id = cycle_id
    with db.get_conn() as conn:
        conn.execute(
            "INSERT INTO poll_cycles (id, started_at, total_docs, skipped) VALUES (?, datetime('now'), ?, ?)",
            (cycle_id, len(batch), skipped),
        )

    classified = 0
    auto_committed = 0
    errored = 0
    try:
        if progress is not None:
            _set_poll_phase_progress("ocr", len(batch))
        record_event(
            job_id,
            job_type,
            "phase_started",
            "OCR-Phase gestartet.",
            phase="ocr",
            data={"documents": len(batch)},
        )
        if progress is not None and progress.cancelled:
            log.info("poll cancelled before OCR phase")
            return BatchProcessResult(total=len(docs), skipped=skipped, cycle_id=cycle_id)
        batch = await phase_ocr(batch, ollama, paperless, cycle_id, tags)
        record_event(
            job_id,
            job_type,
            "phase_finished",
            "OCR-Phase abgeschlossen.",
            phase="ocr",
            level="success",
        )

        if progress is not None:
            _set_poll_phase_progress("embed", len(batch))
        record_event(
            job_id,
            job_type,
            "phase_started",
            "Embedding-Phase gestartet.",
            phase="embed",
            data={"documents": len(batch)},
        )
        if progress is not None and progress.cancelled:
            log.info("poll cancelled before embed phase")
            return BatchProcessResult(total=len(docs), skipped=skipped, cycle_id=cycle_id)
        embed_results = await phase_embed(batch, paperless, ollama, cycle_id)
        record_event(
            job_id,
            job_type,
            "phase_finished",
            "Embedding-Phase abgeschlossen.",
            phase="embed",
            level="success",
        )

        if progress is not None:
            _set_poll_phase_progress("classify", len(batch))
        record_event(
            job_id,
            job_type,
            "phase_started",
            "Klassifizierung gestartet.",
            phase="classify",
            data={"documents": len(batch)},
        )
        if progress is not None and progress.cancelled:
            log.info("poll cancelled before classify phase")
            return BatchProcessResult(total=len(docs), skipped=skipped, cycle_id=cycle_id)
        classified, auto_committed, errored = await phase_classify(
            batch,
            embed_results,
            paperless,
            ollama,
            correspondents,
            doctypes,
            storage_paths,
            tags,
            cycle_id,
        )
        return BatchProcessResult(
            total=len(docs),
            skipped=skipped,
            classified=classified,
            auto_committed=auto_committed,
            errored=errored,
            cycle_id=cycle_id,
        )
    finally:
        with db.get_conn() as conn:
            conn.execute(
                "UPDATE poll_cycles SET finished_at = datetime('now'), succeeded = ?, failed = ? WHERE id = ?",
                (classified + auto_committed, errored, cycle_id),
            )


def resolve_entity(name: str | None, entities: list[PaperlessEntity]) -> int | None:
    """Case-insensitive exact match of an entity name against a Paperless entity list."""
    if not name:
        return None
    lower = name.lower()
    for entity in entities:
        if entity.name.lower() == lower:
            return entity.id
    return None


def upsert_tag_proposal(name: str) -> None:
    """Insert a new Tag proposal or bump its counter. Skips blacklisted Tags."""
    with db.get_conn() as conn:
        blacklisted = conn.execute(
            "SELECT 1 FROM tag_blacklist WHERE LOWER(name) = LOWER(?)", (name,)
        ).fetchone()
        if blacklisted:
            log.debug("tag blacklisted, skipping", tag=name)
            return

        row = conn.execute(
            "SELECT name, times_seen FROM tag_whitelist WHERE LOWER(name) = LOWER(?)",
            (name,),
        ).fetchone()
        if row:
            conn.execute(
                "UPDATE tag_whitelist SET times_seen = times_seen + 1 WHERE name = ?",
                (row["name"],),
            )
        else:
            conn.execute("INSERT INTO tag_whitelist (name) VALUES (?)", (name,))


def upsert_correspondent_proposal(name: str) -> None:
    """Insert a new Korrespondent proposal or bump its counter. Skips blacklisted ones."""
    with db.get_conn() as conn:
        blacklisted = conn.execute(
            "SELECT 1 FROM correspondent_blacklist WHERE LOWER(name) = LOWER(?)", (name,)
        ).fetchone()
        if blacklisted:
            log.debug("correspondent blacklisted, skipping", correspondent=name)
            return

        row = conn.execute(
            "SELECT name, times_seen FROM correspondent_whitelist WHERE LOWER(name) = LOWER(?)",
            (name,),
        ).fetchone()
        if row:
            conn.execute(
                "UPDATE correspondent_whitelist SET times_seen = times_seen + 1 WHERE name = ?",
                (row["name"],),
            )
        else:
            conn.execute("INSERT INTO correspondent_whitelist (name) VALUES (?)", (name,))


def upsert_doctype_proposal(name: str) -> None:
    """Insert a new Dokumenttyp proposal or bump its counter. Skips blacklisted ones."""
    with db.get_conn() as conn:
        blacklisted = conn.execute(
            "SELECT 1 FROM doctype_blacklist WHERE LOWER(name) = LOWER(?)", (name,)
        ).fetchone()
        if blacklisted:
            log.debug("doctype blacklisted, skipping", doctype=name)
            return

        row = conn.execute(
            "SELECT name, times_seen FROM doctype_whitelist WHERE LOWER(name) = LOWER(?)",
            (name,),
        ).fetchone()
        if row:
            conn.execute(
                "UPDATE doctype_whitelist SET times_seen = times_seen + 1 WHERE name = ?",
                (row["name"],),
            )
        else:
            conn.execute("INSERT INTO doctype_whitelist (name) VALUES (?)", (name,))


def resolve_tags(
    proposed_tags: list[dict],
    existing_tags: list[PaperlessEntity],
) -> tuple[list[int], list[dict]]:
    """Resolve proposed Tag names to Paperless IDs and stage unknown Tags for approval."""
    resolved_ids: list[int] = []
    tag_dicts: list[dict] = []

    for proposed in proposed_tags:
        name = proposed.get("name", "")
        confidence = proposed.get("confidence", 50)
        tag_id = resolve_entity(name, existing_tags)
        tag_dicts.append({"name": name, "confidence": confidence, "id": tag_id})
        if tag_id is not None:
            resolved_ids.append(tag_id)
        else:
            upsert_tag_proposal(name)

    return resolved_ids, tag_dicts


def store_suggestion(
    doc: PaperlessDocument,
    result: ClassificationResult,
    raw_response: str,
    correspondents: list[PaperlessEntity],
    doctypes: list[PaperlessEntity],
    storage_paths: list[PaperlessEntity],
    existing_tags: list[PaperlessEntity],
    similar_results: list[SimilarDocument] | None = None,
    judge_verdict: str | None = None,
    judge_reasoning: str | None = None,
    original_proposed_json: str | None = None,
) -> SuggestionRow:
    """Persist a Klassifikation result as a reviewable Vorschlag."""
    corr_id = resolve_entity(result.correspondent, correspondents)
    if corr_id is None and result.correspondent:
        upsert_correspondent_proposal(result.correspondent)
    dt_id = resolve_entity(result.document_type, doctypes)
    if dt_id is None and result.document_type:
        upsert_doctype_proposal(result.document_type)
    sp_id = resolve_entity(result.storage_path, storage_paths)
    _resolved_tag_ids, tag_dicts = resolve_tags(
        [{"name": tag.name, "confidence": tag.confidence} for tag in result.tags],
        existing_tags,
    )

    context_json = json.dumps(
        [
            {
                "id": item.document.id,
                "title": item.document.title,
                "distance": round(item.distance, 6),
            }
            for item in (similar_results or [])
        ],
        ensure_ascii=False,
    )

    with db.get_conn() as conn:
        cur = conn.execute(
            """
            INSERT INTO suggestions (
                document_id, confidence, reasoning,
                original_title, original_date, original_correspondent,
                original_doctype, original_storage_path, original_tags_json,
                proposed_title, proposed_date,
                proposed_correspondent_name, proposed_correspondent_id,
                proposed_doctype_name, proposed_doctype_id,
                proposed_storage_path_name, proposed_storage_path_id,
                proposed_tags_json, raw_response, context_docs_json,
                judge_verdict, judge_reasoning, original_proposed_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                doc.id,
                result.confidence,
                result.reasoning,
                doc.title,
                doc.created_date,
                doc.correspondent,
                doc.document_type,
                doc.storage_path,
                json.dumps(doc.tags),
                result.title,
                result.date,
                result.correspondent,
                corr_id,
                result.document_type,
                dt_id,
                result.storage_path,
                sp_id,
                json.dumps(tag_dicts, ensure_ascii=False),
                raw_response,
                context_json,
                judge_verdict,
                judge_reasoning,
                original_proposed_json,
            ),
        )
        suggestion_id = cur.lastrowid

        conn.execute(
            """
            INSERT OR REPLACE INTO processed_documents
                (document_id, last_updated_at, last_processed, status, suggestion_id)
            VALUES (?, ?, datetime('now'), 'pending', ?)
            """,
            (doc.id, (doc.modified or datetime.now(tz=UTC)).isoformat(), suggestion_id),
        )

    return SuggestionRow(
        id=suggestion_id,
        document_id=doc.id,
        created_at=datetime.now(tz=UTC).isoformat(),
        status="pending",
        confidence=result.confidence,
        reasoning=result.reasoning,
        original_title=doc.title,
        original_date=doc.created_date,
        original_correspondent=doc.correspondent,
        original_doctype=doc.document_type,
        original_storage_path=doc.storage_path,
        original_tags_json=json.dumps(doc.tags),
        proposed_title=result.title,
        proposed_date=result.date,
        proposed_correspondent_name=result.correspondent,
        proposed_correspondent_id=corr_id,
        proposed_doctype_name=result.document_type,
        proposed_doctype_id=dt_id,
        proposed_storage_path_name=result.storage_path,
        proposed_storage_path_id=sp_id,
        proposed_tags_json=json.dumps(tag_dicts, ensure_ascii=False),
        judge_verdict=judge_verdict,
        judge_reasoning=judge_reasoning,
        original_proposed_json=original_proposed_json,
    )
