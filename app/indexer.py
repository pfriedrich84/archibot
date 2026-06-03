"""Legacy re-index jobs for document embeddings.

Target event-driven embedding builds use PostgreSQL/pgvector helpers in
``app.actors.embedding`` and ``app.jobs.document_embeddings``. This module is
kept for legacy CLI progress/OCR orchestration during migration.
"""

from __future__ import annotations

import asyncio
import json
import sys
import uuid
from dataclasses import dataclass
from datetime import UTC, datetime

import structlog

from app.clients.paperless import PaperlessClient
from app.config import settings
from app.db import get_conn
from app.job_events import record_event
from app.pipeline.context_builder import document_summary, store_embedding
from app.pipeline.ocr_correction import (
    cache_ocr_correction,
    effective_ocr_mode,
    get_cached_ocr,
    maybe_correct_ocr,
    should_run_ocr_for_document,
)
from app.pipeline.ports import AiProviderGateway
from app.pipeline.trusted_context import is_trusted_document

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
    failed_document_ids: list[int] | None = None


_reindex_progress = ReindexProgress()
_reindex_task: asyncio.Task | None = None
_reindex_progress_stdout_enabled = False


def enable_reindex_progress_stdout(enabled: bool = True) -> None:
    """Emit machine-readable progress lines for Laravel worker jobs."""
    global _reindex_progress_stdout_enabled
    _reindex_progress_stdout_enabled = enabled


def _emit_reindex_progress(**extra: object) -> None:
    if not _reindex_progress_stdout_enabled:
        return

    payload = {
        "running": _reindex_progress.running,
        "phase": _reindex_progress.phase,
        "done": _reindex_progress.done,
        "total": _reindex_progress.total,
        "failed": _reindex_progress.failed,
        "cancelled": _reindex_progress.cancelled,
        "error": _reindex_progress.error,
        "started_at": _reindex_progress.started_at,
        "finished_at": _reindex_progress.finished_at,
        "failed_document_ids": _reindex_progress.failed_document_ids or [],
        **extra,
    }
    print("PROGRESS " + json.dumps(payload, ensure_ascii=False, default=str), flush=True)
    sys.stdout.flush()


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
    ollama: AiProviderGateway,
    limit: int | None = None,
) -> int:
    """Embed all already-classified documents that are not yet indexed.

    Returns the number of newly indexed documents.
    """
    log.info("starting initial embedding index", limit=limit)
    docs = await paperless.list_all_documents(limit=limit)

    # PostgreSQL/pgvector is the target context store. Build only trusted
    # documents; PostgreSQL upsert semantics handle existing embeddings.
    new_docs = [document for document in docs if is_trusted_document(document)]
    log.info("documents to index", total=len(docs), trusted=len(new_docs))

    # Update progress tracking with total count
    _reindex_progress.total = len(new_docs)
    _reindex_progress.failed_document_ids = []
    _reindex_progress.phase = "embedding"
    _emit_reindex_progress(event="phase_started", message="Embedding phase started")
    record_event(
        _reindex_progress.job_id,
        _reindex_progress.job_type or "reindex",
        "phase_started",
        f"Embedding-Index wird für {len(new_docs)} Dokumente aufgebaut.",
        phase="embedding",
        data={"total": len(new_docs), "trusted_scope": "without_inbox_tag"},
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

            content_length = len(doc.content or "")
            summary = document_summary(doc)
            embedding_text = "\n".join(p for p in [doc.title or "", doc.content or ""] if p)
            truncated = len(embedding_text) > len(summary)
            _emit_reindex_progress(
                event="document_started",
                document_id=doc.id,
                document_title=getattr(doc, "title", None),
                document_index=i,
                document_total=len(new_docs),
                content_length=content_length,
                embedding_max_chars=settings.embed_max_chars,
                truncated=truncated,
                message="Document embedding started",
            )
            record_event(
                _reindex_progress.job_id,
                _reindex_progress.job_type or "reindex",
                "document_started",
                "Dokument wird in den Embedding-Index aufgenommen.",
                phase="embedding",
                document_id=doc.id,
                data={
                    "document_index": i,
                    "total": len(new_docs),
                    "content_length": content_length,
                    "embedding_max_chars": settings.embed_max_chars,
                    "truncated": truncated,
                },
            )

            if not summary.strip():
                raise ValueError("document has no text to embed")

            vec = await asyncio.wait_for(
                ollama.embed(summary),
                timeout=max(0.001, float(settings.embedding_document_timeout_seconds)),
            )
            store_embedding(doc, vec)
            count += 1
            _emit_reindex_progress(
                event="document_done",
                document_id=doc.id,
                document_title=getattr(doc, "title", None),
                document_index=i,
                document_total=len(new_docs),
                content_length=content_length,
                embedding_max_chars=settings.embed_max_chars,
                truncated=truncated,
                message="Document embedded",
            )
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
            if _reindex_progress.failed_document_ids is None:
                _reindex_progress.failed_document_ids = []
            _reindex_progress.failed_document_ids.append(doc.id)
            error = "embedding document timeout" if isinstance(exc, TimeoutError) else str(exc)
            log.warning("failed to index document", doc_id=doc.id, error=error)
            _emit_reindex_progress(
                event="document_failed",
                document_id=doc.id,
                document_title=getattr(doc, "title", None),
                document_index=i,
                document_total=len(new_docs),
                content_length=len(doc.content or ""),
                message="Document embedding failed",
                error=error,
                level="error",
            )
            record_event(
                _reindex_progress.job_id,
                _reindex_progress.job_type or "reindex",
                "document_failed",
                "Dokument konnte nicht indexiert werden.",
                phase="embedding",
                level="error",
                document_id=doc.id,
                data={"error": error, "done": i, "total": len(new_docs)},
            )
        finally:
            _reindex_progress.done = i
            _emit_reindex_progress(event="progress", document_id=doc.id)
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
            "failed_document_ids": _reindex_progress.failed_document_ids or [],
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
    ollama: AiProviderGateway,
) -> int:
    """Drop all embeddings and rebuild from scratch.

    Use this when the embedding model changes.
    """
    try:
        log.info("starting full reindex — rebuilding PostgreSQL/pgvector embeddings")
        _reindex_progress.phase = "prepare"
        record_event(
            _reindex_progress.job_id,
            _reindex_progress.job_type or "reindex",
            "job_started",
            "Reindex gestartet.",
            phase="prepare",
        )
        # PostgreSQL/pgvector upsert semantics replace matching embeddings for
        # the active content hash/model/dimension. Legacy SQLite vector tables
        # are no longer rebuilt by the target reindex path.

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
                        _emit_reindex_progress(
                            event="document_done",
                            phase="ocr",
                            document_id=doc.id,
                            document_title=getattr(doc, "title", None),
                            message="OCR correction saved",
                        )
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
                    _emit_reindex_progress(
                        event="document_failed",
                        phase="ocr",
                        document_id=doc.id,
                        document_title=getattr(doc, "title", None),
                        message="OCR correction failed",
                        error=str(exc),
                    )
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
        _emit_reindex_progress(event="job_finished", message="Reindex finished", indexed=result)
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
        _emit_reindex_progress(event="job_failed", message="Reindex failed", error=str(exc))
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
    ollama: AiProviderGateway,
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
