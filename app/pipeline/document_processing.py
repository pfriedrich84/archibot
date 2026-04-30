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
    cache_ocr_correction,
    effective_ocr_mode,
    maybe_correct_ocr,
    should_run_ocr_for_document,
)
from app.pipeline.ports import DocumentRepository, LlmGateway
from app.pipeline.processing_models import (
    BatchProcessResult,
    EmbeddingResult,
    JudgeOutcome,
    ProcessResult,
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
    if not settings.enable_judge_verification:
        return JudgeOutcome(result=initial)
    if initial.confidence >= settings.judge_confidence_threshold:
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
        return docs

    log.info("phase ocr started", count=len(docs), mode=ocr_mode)
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
                record_phase_timing(cycle_id, doc.id, "ocr", t0, success=True)
                corrected.append(doc)
                continue
            text, num_corrections = await maybe_correct_ocr(doc, ollama, paperless)
            if num_corrections > 0:
                doc = doc.model_copy(update={"content": text})
                cache_ocr_correction(doc.id, text, ocr_mode, num_corrections)
        except Exception as exc:
            ok = False
            log.warning("ocr correction failed", doc_id=doc.id, error=str(exc))
        record_phase_timing(cycle_id, doc.id, "ocr", t0, success=ok)
        corrected.append(doc)

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
            except Exception as exc:
                ok = False
                log.warning("embedding failed", doc_id=doc.id, error=str(exc))
        record_phase_timing(cycle_id, doc.id, "embed", t0, success=ok)
        results[doc.id] = er

    await ollama.unload_model(ollama.embed_model, swap=True)
    return results


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
    """Phase 3: Classify all documents and post-process (chat model + DB writes).

    Returns ``(classified, auto_committed, errored)`` counts.
    """
    from app import worker

    log.info("phase classify started", count=len(docs))
    classified = 0
    auto_committed = 0
    errored = 0

    for doc in docs:
        if worker._poll_progress.cancelled:
            log.info("poll cancelled during classify phase")
            break
        er = embed_results.get(doc.id, EmbeddingResult())
        context_docs = [r.document for r in er.similar_results]

        t0 = time.monotonic()
        classify_recorded = False
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
            classify_recorded = True

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
                cycle_id=cycle_id,
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
                similar_results=er.similar_results,
                judge_verdict=judge.verdict,
                judge_reasoning=judge.reasoning,
                original_proposed_json=judge.original_proposed_json,
            )

            # Notify via Telegram (only if not auto-committing)
            will_auto_commit = (
                settings.auto_commit_confidence > 0
                and result.confidence >= settings.auto_commit_confidence
            )
            if not will_auto_commit:
                await notify_suggestion(suggestion)

            # Auto-commit if confidence is high enough
            if will_auto_commit:
                log.info("auto-committing", doc_id=doc.id, confidence=result.confidence)
                tag_ids = [
                    tid for t in result.tags if (tid := resolve_entity(t.name, tags)) is not None
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

            if will_auto_commit:
                auto_committed += 1
            else:
                classified += 1
            worker._poll_progress.succeeded += 1
        except Exception as exc:
            errored += 1
            worker._poll_progress.failed += 1
            log.error("classification failed", doc_id=doc.id, error=repr(exc))
            worker._write_error("classify", doc.id, exc)
            # Only emit a 'classify' failure row if classify() itself failed.
            # Errors in judge/store/notify/commit must not masquerade as classify
            # failures (would inflate phase_timing error-rate for classify).
            if not classify_recorded:
                record_phase_timing(cycle_id, doc.id, "classify", t0, success=False)
        finally:
            worker._poll_progress.done += 1

        # Index pre-computed embedding regardless of classification outcome
        if er.embedding is not None:
            try:
                context_builder.store_embedding(doc, er.embedding)
            except Exception as exc:
                log.warning("indexing failed", doc_id=doc.id, error=str(exc))

    await ollama.unload_model(ollama.model, swap=True)
    return classified, auto_committed, errored


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
    batch: list[PaperlessDocument] = []
    skipped = 0
    for doc in docs:
        if not force and should_skip_document(doc):
            skipped += 1
            continue
        log.info("processing document", doc_id=doc.id, title=doc.title[:80])
        mark_document_pending(doc)
        batch.append(doc)

    if progress is not None:
        progress.total = len(batch)
        progress.skipped = skipped

    if not batch:
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
            progress.phase = "ocr"
        if progress is not None and progress.cancelled:
            log.info("poll cancelled before OCR phase")
            return BatchProcessResult(total=len(docs), skipped=skipped, cycle_id=cycle_id)
        batch = await phase_ocr(batch, ollama, paperless, cycle_id, tags)

        if progress is not None:
            progress.phase = "embed"
        if progress is not None and progress.cancelled:
            log.info("poll cancelled before embed phase")
            return BatchProcessResult(total=len(docs), skipped=skipped, cycle_id=cycle_id)
        embed_results = await phase_embed(batch, paperless, ollama, cycle_id)

        if progress is not None:
            progress.phase = "classify"
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
