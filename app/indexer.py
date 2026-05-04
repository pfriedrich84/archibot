"""Initial and re-index jobs for the document embedding store."""

from __future__ import annotations

import asyncio
import uuid
from dataclasses import dataclass
from datetime import UTC, datetime

import structlog

from app.clients.ollama import OllamaClient
from app.clients.paperless import PaperlessClient
from app.config import settings
from app.db import EMBED_DIM, get_conn
from app.job_events import record_event
from app.pipeline.context_builder import index_document
from app.pipeline.ocr_correction import (
    cache_ocr_correction,
    effective_ocr_mode,
    get_cached_ocr,
    maybe_correct_ocr,
    should_run_ocr_for_document,
)

log = structlog.get_logger(__name__)


# ---------------------------------------------------------------------------
# Reindex progress tracking
# ---------------------------------------------------------------------------
@dataclass
class ReindexProgress:
    """Module-level state for tracking a running reindex job."""

    running: bool = False
    total: int = 0
    done: int = 0
    failed: int = 0
    started_at: str | None = None
    finished_at: str | None = None
    error: str | None = None
    cancelled: bool = False
    phase: str = "idle"
    job_id: str | None = None
    job_type: str | None = None


_reindex_progress = ReindexProgress()
_reindex_task: asyncio.Task | None = None


def get_reindex_progress() -> ReindexProgress:
    """Return the current reindex progress (read-only snapshot)."""
    return _reindex_progress


def is_reindexing() -> bool:
    """Return ``True`` while a reindex task is running."""
    return _reindex_progress.running


def cancel_reindex() -> bool:
    """Request cancellation of the running reindex task.

    Returns ``True`` if cancellation was requested, ``False`` if not running.
    """
    if not _reindex_progress.running:
        return False
    _reindex_progress.cancelled = True
    return True


# ---------------------------------------------------------------------------
# Indexing
# ---------------------------------------------------------------------------
async def initial_index(
    paperless: PaperlessClient,
    ollama: OllamaClient,
    limit: int | None = None,
) -> int:
    """Embed all already-classified documents that are not yet indexed.

    Returns the number of newly indexed documents.
    """
    log.info("starting initial embedding index", limit=limit)
    docs = await paperless.list_all_documents(limit=limit)

    # Determine which docs already have embeddings
    with get_conn() as conn:
        rows = conn.execute("SELECT document_id FROM doc_embedding_meta").fetchall()
    indexed_ids = {r["document_id"] for r in rows}

    new_docs = [d for d in docs if d.id not in indexed_ids]
    log.info(
        "documents to index", total=len(docs), already_indexed=len(indexed_ids), new=len(new_docs)
    )

    # Update progress tracking with total count
    _reindex_progress.total = len(new_docs)
    _reindex_progress.phase = "embedding"
    record_event(
        _reindex_progress.job_id,
        _reindex_progress.job_type or "reindex",
        "phase_started",
        f"Embedding-Index wird für {len(new_docs)} Dokumente aufgebaut.",
        phase="embedding",
        data={"total": len(new_docs), "already_indexed": len(indexed_ids)},
    )

    ollama.embed_retry_count = 0
    count = 0
    for i, doc in enumerate(new_docs, 1):
        if _reindex_progress.cancelled:
            log.info("reindex cancelled by user", done=i - 1, total=len(new_docs))
            record_event(
                _reindex_progress.job_id,
                _reindex_progress.job_type or "reindex",
                "job_cancelled",
                "Reindex wurde abgebrochen.",
                phase="embedding",
                level="warning",
                data={"done": i - 1, "total": len(new_docs)},
            )
            break
        try:
            # Use cached OCR-corrected text if available
            cached = get_cached_ocr(doc.id)
            if cached:
                doc = doc.model_copy(update={"content": cached})
            await index_document(doc, ollama)
            count += 1
            record_event(
                _reindex_progress.job_id,
                _reindex_progress.job_type or "reindex",
                "document_done",
                "Dokument wurde in den Embedding-Index aufgenommen.",
                phase="embedding",
                document_id=doc.id,
                data={"done": i, "total": len(new_docs)},
            )
        except Exception as exc:
            _reindex_progress.failed += 1
            log.warning("failed to index document", doc_id=doc.id, error=str(exc))
            record_event(
                _reindex_progress.job_id,
                _reindex_progress.job_type or "reindex",
                "document_failed",
                "Dokument konnte nicht indexiert werden.",
                phase="embedding",
                level="error",
                document_id=doc.id,
                data={"error": str(exc), "done": i, "total": len(new_docs)},
            )
        finally:
            _reindex_progress.done = i
            if i % 50 == 0:
                log.info("index progress", done=i, total=len(new_docs))
                record_event(
                    _reindex_progress.job_id,
                    _reindex_progress.job_type or "reindex",
                    "progress",
                    f"{i}/{len(new_docs)} Dokumente indexiert.",
                    phase="embedding",
                    data={"done": i, "total": len(new_docs)},
                )

    log.info(
        "initial index complete",
        indexed=count,
        skipped=len(new_docs) - count,
        embed_retries=ollama.embed_retry_count,
    )
    record_event(
        _reindex_progress.job_id,
        _reindex_progress.job_type or "reindex",
        "phase_finished",
        f"Embedding-Phase abgeschlossen: {count} Dokumente indexiert, {_reindex_progress.failed} Fehler.",
        phase="embedding",
        level="success" if _reindex_progress.failed == 0 else "warning",
        data={
            "indexed": count,
            "failed": _reindex_progress.failed,
            "embed_retries": ollama.embed_retry_count,
        },
    )
    if ollama.embed_retry_count > 0:
        log.warning(
            "embedding retries occurred — lower EMBED_MAX_CHARS to avoid extra round-trips",
            retries=ollama.embed_retry_count,
            current_embed_max_chars=settings.embed_max_chars,
        )

    # Persist "index built successfully" marker so poll_inbox knows it can run
    if count > 0:
        with get_conn() as conn:
            conn.execute(
                "INSERT INTO audit_log (action, actor, details) VALUES (?, ?, ?)",
                ("index_complete", "system", f"indexed={count}"),
            )

    return count


async def reindex_all(
    paperless: PaperlessClient,
    ollama: OllamaClient,
) -> int:
    """Drop all embeddings and rebuild from scratch.

    Use this when the embedding model changes.
    """
    try:
        log.info("starting full reindex — clearing existing embeddings and FTS index")
        _reindex_progress.phase = "prepare"
        record_event(
            _reindex_progress.job_id,
            _reindex_progress.job_type or "reindex",
            "job_started",
            "Reindex gestartet.",
            phase="prepare",
        )
        with get_conn() as conn:
            conn.execute("DELETE FROM doc_embedding_meta")
            # Drop + recreate vec0 table so dimension changes take effect
            conn.execute("DROP TABLE IF EXISTS doc_embeddings")
            conn.execute(
                f"""CREATE VIRTUAL TABLE doc_embeddings USING vec0(
                    document_id INTEGER PRIMARY KEY,
                    embedding   FLOAT[{EMBED_DIM}]
                )"""
            )
            conn.execute("DELETE FROM doc_fts")

        # --- Phase 0: OCR correction (before embedding) ---
        ocr_mode = effective_ocr_mode()
        if ocr_mode != "off":
            log.info("reindex phase ocr — correcting documents", mode=ocr_mode)
            _reindex_progress.phase = "ocr"
            docs = await paperless.list_all_documents()
            record_event(
                _reindex_progress.job_id,
                _reindex_progress.job_type or "reindex",
                "phase_started",
                f"OCR-Phase gestartet ({ocr_mode}) für {len(docs)} Dokumente.",
                phase="ocr",
                data={"mode": ocr_mode, "total": len(docs)},
            )
            available_tags = await paperless.list_tags()
            corrected = 0
            for doc in docs:
                if _reindex_progress.cancelled:
                    log.info("reindex cancelled during OCR phase")
                    record_event(
                        _reindex_progress.job_id,
                        _reindex_progress.job_type or "reindex",
                        "job_cancelled",
                        "Reindex wurde während der OCR-Phase abgebrochen.",
                        phase="ocr",
                        level="warning",
                    )
                    break
                try:
                    eligible, reason = should_run_ocr_for_document(
                        doc, available_tags=available_tags
                    )
                    if not eligible:
                        log.debug(
                            "reindex OCR skipped by requested tag filter",
                            doc_id=doc.id,
                            reason=reason,
                        )
                        continue
                    text, num = await maybe_correct_ocr(doc, ollama, paperless)
                    if num > 0 or ocr_mode.startswith("vision"):
                        cache_ocr_correction(doc.id, text, ocr_mode, num)
                        corrected += 1
                        record_event(
                            _reindex_progress.job_id,
                            _reindex_progress.job_type or "reindex",
                            "document_done",
                            "OCR-Korrektur wurde gespeichert.",
                            phase="ocr",
                            document_id=doc.id,
                            data={"corrections": num, "mode": ocr_mode},
                        )
                except Exception as exc:
                    log.warning("reindex ocr failed", doc_id=doc.id, error=str(exc))
                    record_event(
                        _reindex_progress.job_id,
                        _reindex_progress.job_type or "reindex",
                        "document_failed",
                        "OCR-Korrektur ist fehlgeschlagen.",
                        phase="ocr",
                        level="error",
                        document_id=doc.id,
                        data={"error": str(exc)},
                    )
            log.info("reindex phase ocr complete", corrected=corrected, total=len(docs))
            record_event(
                _reindex_progress.job_id,
                _reindex_progress.job_type or "reindex",
                "phase_finished",
                f"OCR-Phase abgeschlossen: {corrected} Dokumente korrigiert.",
                phase="ocr",
                level="success",
                data={"corrected": corrected, "total": len(docs)},
            )

            # Unload OCR/vision model before embedding phase
            if ocr_mode == "text":
                await ollama.unload_model(ollama.ocr_model, swap=True)
            else:
                vision_model = settings.ocr_vision_model or ollama.model
                await ollama.unload_model(vision_model, swap=True)

        # --- Phase 1: Embedding (uses cached OCR text if available) ---
        result = await initial_index(paperless, ollama)
        _reindex_progress.finished_at = datetime.now(tz=UTC).isoformat()
        _reindex_progress.phase = "finished"
        record_event(
            _reindex_progress.job_id,
            _reindex_progress.job_type or "reindex",
            "job_finished",
            f"Reindex abgeschlossen: {result} Dokumente indexiert.",
            phase="finished",
            level="success" if _reindex_progress.failed == 0 else "warning",
            data={"indexed": result, "failed": _reindex_progress.failed},
        )
        return result
    except Exception as exc:
        _reindex_progress.error = str(exc)
        record_event(
            _reindex_progress.job_id,
            _reindex_progress.job_type or "reindex",
            "job_failed",
            "Reindex ist fehlgeschlagen.",
            phase=_reindex_progress.phase,
            level="error",
            data={"error": str(exc)},
        )
        raise
    finally:
        _reindex_progress.running = False


def start_reindex_task(
    paperless: PaperlessClient,
    ollama: OllamaClient,
) -> bool:
    """Launch ``reindex_all`` as a background asyncio task.

    Returns ``True`` if started, ``False`` if already running.
    """
    if _reindex_progress.running:
        return False

    # Initialise progress BEFORE creating the task so the HTTP response
    # immediately sees running=True (fixes the race condition).
    _reindex_progress.running = True
    _reindex_progress.total = 0
    _reindex_progress.done = 0
    _reindex_progress.failed = 0
    _reindex_progress.started_at = datetime.now(tz=UTC).isoformat()
    _reindex_progress.finished_at = None
    _reindex_progress.error = None
    _reindex_progress.cancelled = False
    _reindex_progress.phase = "prepare"
    _reindex_progress.job_type = "reindex"
    _reindex_progress.job_id = f"reindex-{uuid.uuid4().hex[:12]}"

    async def _run() -> None:
        try:
            await reindex_all(paperless, ollama)
        except Exception as exc:
            log.error("background reindex failed", error=str(exc))

    global _reindex_task
    _reindex_task = asyncio.create_task(_run())
    return True
