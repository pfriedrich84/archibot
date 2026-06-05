"""APScheduler-based background worker for inbox polling and classification."""

from __future__ import annotations

import asyncio
import json
import uuid
from dataclasses import dataclass
from datetime import UTC, datetime

import structlog
from apscheduler.schedulers.asyncio import AsyncIOScheduler

from app.clients.paperless import PaperlessClient
from app.config import settings
from app.db import get_conn
from app.indexer import is_reindexing
from app.job_events import record_event
from app.jobs.embedding_gate import latest_embedding_index_status
from app.pipeline.ocr_correction import configured_ocr_tag_exists, ocr_requested_tag_id
from app.pipeline.ports import AiProviderGateway

log = structlog.get_logger(__name__)

# Module-level refs set by start_scheduler
_paperless: PaperlessClient | None = None
_ollama: AiProviderGateway | None = None


def set_clients(paperless: PaperlessClient | None, ollama: AiProviderGateway | None) -> None:
    """Update module-level client references used by poll jobs."""
    global _paperless, _ollama
    _paperless = paperless
    _ollama = ollama


# ---------------------------------------------------------------------------
# Poll progress tracking
# ---------------------------------------------------------------------------
@dataclass
class PollProgress:
    """Module-level state for tracking a running poll job."""

    running: bool = False
    total: int = 0
    done: int = 0
    succeeded: int = 0
    failed: int = 0
    skipped: int = 0
    phase: str = ""  # "prepare", "ocr", "embed", "classify"
    phase_done: int = 0
    phase_total: int = 0
    cancelled: bool = False
    error: str | None = None
    started_at: str | None = None  # ISO timestamp when this poll started
    cycle_id: str | None = None  # links to poll_cycles table
    job_id: str | None = None  # links to persistent job_events
    job_type: str | None = None


_poll_progress = PollProgress()
_poll_task: asyncio.Task | None = None
_poll_progress_stdout_enabled = False


def enable_poll_progress_stdout(enabled: bool = True) -> None:
    """Emit machine-readable poll progress lines for Laravel worker jobs."""
    global _poll_progress_stdout_enabled
    _poll_progress_stdout_enabled = enabled


def emit_poll_progress(**extra: object) -> None:
    """Print the current poll progress in the Laravel worker PROGRESS protocol."""
    if not _poll_progress_stdout_enabled:
        return
    payload = {
        "running": _poll_progress.running,
        "phase": _poll_progress.phase,
        "done": _poll_progress.phase_done,
        "total": _poll_progress.phase_total,
        "phase_done": _poll_progress.phase_done,
        "phase_total": _poll_progress.phase_total,
        "overall_done": _poll_progress.done,
        "overall_total": _poll_progress.total,
        "succeeded": _poll_progress.succeeded,
        "failed": _poll_progress.failed,
        "skipped": _poll_progress.skipped,
        "cancelled": _poll_progress.cancelled,
        "error": _poll_progress.error,
        "started_at": _poll_progress.started_at,
        "cycle_id": _poll_progress.cycle_id,
        "job_id": _poll_progress.job_id,
        "job_type": _poll_progress.job_type,
    }
    payload.update(extra)
    print("PROGRESS " + json.dumps(payload, ensure_ascii=False, default=str), flush=True)


def get_poll_progress() -> PollProgress:
    """Return the current poll progress."""
    return _poll_progress


def is_polling() -> bool:
    """Return ``True`` while a manual poll task is running."""
    return _poll_progress.running


def cancel_poll() -> bool:
    """Request cancellation of the running poll task.

    Returns ``True`` if cancellation was requested, ``False`` if not running.
    """
    if not _poll_progress.running:
        return False
    _poll_progress.cancelled = True
    return True


def _has_embedding_index() -> bool:
    """Return ``True`` when the embedding index is ready for document work.

    The event-driven pipeline stores readiness in PostgreSQL
    ``embedding_index_state``.  When that durable state exists it is the source
    of truth, even if the reported embedded/total counters differ because some
    Paperless documents are intentionally not indexed yet.

    Older local/CLI installs only have the legacy SQLite completion marker, so
    fall back to that when the PostgreSQL state is absent or unavailable.
    """
    try:
        status = latest_embedding_index_status()
    except Exception as exc:  # pragma: no cover - defensive fallback for legacy/local installs
        log.debug(
            "embedding index durable state unavailable; falling back to legacy marker",
            error=str(exc),
        )
    else:
        if status is not None:
            return status == "complete"

    with get_conn() as conn:
        completed = conn.execute(
            "SELECT 1 FROM audit_log WHERE action = 'index_complete' LIMIT 1"
        ).fetchone()
        if not completed:
            return False
        count = conn.execute("SELECT COUNT(*) AS c FROM doc_embedding_meta").fetchone()
    return count["c"] > 0


def start_poll_task(*, all_documents: bool = False) -> bool:
    """Launch ``poll_inbox`` as a background asyncio task.

    Returns ``True`` if started, ``False`` if already running, reindexing,
    or no embedding index exists yet.
    """
    if _poll_progress.running or is_reindexing() or not _has_embedding_index():
        return False

    # Initialise progress BEFORE creating the task so the HTTP response
    # immediately sees running=True.
    _poll_progress.running = True
    _poll_progress.total = 0
    _poll_progress.done = 0
    _poll_progress.succeeded = 0
    _poll_progress.failed = 0
    _poll_progress.skipped = 0
    _poll_progress.phase = "prepare"
    _poll_progress.phase_done = 0
    _poll_progress.phase_total = 0
    _poll_progress.cancelled = False
    _poll_progress.error = None
    _poll_progress.started_at = datetime.now(tz=UTC).isoformat()
    _poll_progress.cycle_id = None
    _poll_progress.job_type = "poll_all" if all_documents else "poll"
    _poll_progress.job_id = f"{_poll_progress.job_type}-{uuid.uuid4().hex[:12]}"
    record_event(
        _poll_progress.job_id,
        _poll_progress.job_type,
        "job_started",
        "Prüfung aller Dokumente gestartet." if all_documents else "Posteingang-Prüfung gestartet.",
        phase="prepare",
    )

    async def _run() -> None:
        try:
            await poll_inbox(all_documents=all_documents)
        except Exception as exc:
            _poll_progress.error = str(exc)
            record_event(
                _poll_progress.job_id,
                _poll_progress.job_type or "poll",
                "job_failed",
                "Job fehlgeschlagen.",
                level="error",
                data={"error": str(exc)[:300]},
            )
            log.error("background poll failed", error=str(exc))
        finally:
            if _poll_progress.error is None:
                record_event(
                    _poll_progress.job_id,
                    _poll_progress.job_type or "poll",
                    "job_finished",
                    "Job abgeschlossen.",
                    level="success",
                    data={
                        "done": _poll_progress.done,
                        "failed": _poll_progress.failed,
                        "skipped": _poll_progress.skipped,
                    },
                )
            _poll_progress.running = False

    global _poll_task
    _poll_task = asyncio.create_task(_run())
    return True


# ---------------------------------------------------------------------------
# Main poll loop
# ---------------------------------------------------------------------------
async def poll_inbox(*, force: bool = False, all_documents: bool = False) -> None:
    """Fetch inbox or all Paperless documents and run the classification pipeline.

    Processing is split into phases to minimise Ollama model swaps:

    1. **OCR correction** (chat model, optional) — all docs
    2. **Embedding + context search** (embed model) — all docs
    3. **Classification + post-processing** (chat model) — all docs

    Each phase unloads its model from VRAM before the next phase begins.

    When ``force=True``, the idempotency skip check is bypassed and documents
    are reprocessed even if their ``modified`` timestamp did not change.
    When ``all_documents=True``, every Paperless document is considered instead
    of only documents with the configured inbox tag.
    """
    if _paperless is None or _ollama is None:
        log.error("worker not initialised — skipping poll")
        return

    if is_reindexing():
        log.info("reindex in progress — skipping poll")
        return

    if not _has_embedding_index():
        record_event(
            _poll_progress.job_id,
            _poll_progress.job_type or "poll",
            "job_blocked",
            "Embedding-Index fehlt. Bitte zuerst Reindex starten.",
            level="warning",
            phase="prepare",
        )
        log.info("no embedding index yet — skipping poll (run reindex first)")
        return

    source = "all documents" if all_documents else "inbox"
    record_event(
        _poll_progress.job_id,
        _poll_progress.job_type or "poll",
        "fetch_started",
        "Dokumente werden aus Paperless geladen.",
        phase="fetch",
        data={"source": source},
    )
    log.info("polling documents", source=source)
    try:
        docs = (
            await _paperless.list_all_documents()
            if all_documents
            else await _paperless.list_inbox_documents(settings.paperless_inbox_tag_id)
        )
    except Exception as exc:
        record_event(
            _poll_progress.job_id,
            _poll_progress.job_type or "poll",
            "fetch_failed",
            "Dokumente konnten nicht geladen werden.",
            phase="fetch",
            level="error",
            data={"error": str(exc)[:300]},
        )
        log.error("failed to fetch documents", source=source, error=str(exc))
        _write_error("poll", None, exc)
        return

    if not docs:
        record_event(
            _poll_progress.job_id,
            _poll_progress.job_type or "poll",
            "fetch_empty",
            "Keine Dokumente zum Prüfen gefunden.",
            phase="fetch",
            level="success",
            data={"source": source},
        )
        log.info("no documents to process", source=source)
        return

    record_event(
        _poll_progress.job_id,
        _poll_progress.job_type or "poll",
        "fetch_done",
        f"{len(docs)} Dokumente geladen.",
        phase="fetch",
        level="success",
        data={"count": len(docs), "source": source},
    )

    # Cache entity lists once per cycle
    try:
        correspondents = await _paperless.list_correspondents()
        doctypes = await _paperless.list_document_types()
        storage_paths = await _paperless.list_storage_paths()
        tags = await _paperless.list_tags()
    except Exception as exc:
        record_event(
            _poll_progress.job_id,
            _poll_progress.job_type or "poll",
            "entities_failed",
            "Paperless-Listen konnten nicht geladen werden.",
            phase="prepare",
            level="error",
            data={"error": str(exc)[:300]},
        )
        log.error("failed to fetch entity lists", error=str(exc))
        _write_error("poll", None, exc)
        return

    if not configured_ocr_tag_exists(tags):
        log.warning("configured OCR tag missing in Paperless", tag_id=ocr_requested_tag_id())
        _record_ocr_tag_missing_error()

    from app.pipeline.document_processing import process_batch

    result = await process_batch(
        docs,
        _paperless,
        _ollama,
        correspondents,
        doctypes,
        storage_paths,
        tags,
        force=force,
        progress=_poll_progress,
    )

    log.info(
        "poll cycle complete",
        total=result.total,
        skipped=result.skipped,
        classified=result.classified,
        auto_committed=result.auto_committed,
        errored=result.errored,
    )


def _record_ocr_tag_missing_error() -> None:
    tag_id = ocr_requested_tag_id()
    if tag_id == 0:
        return
    _write_error(
        "ocr_config",
        None,
        RuntimeError(f"Configured OCR tag ID {tag_id} does not exist in Paperless"),
    )


def _write_error(stage: str, doc_id: int | None, exc: Exception) -> None:
    """Compatibility wrapper for recording a Dokument processing error."""
    from app.pipeline.document_processing import record_processing_error

    record_processing_error(stage, doc_id, exc)


# ---------------------------------------------------------------------------
# Scheduler lifecycle
# ---------------------------------------------------------------------------
async def _scheduled_poll() -> None:
    """Wrapper for APScheduler that skips when a manual poll is running."""
    if _poll_progress.running:
        log.info("manual poll in progress — skipping scheduled poll")
        return
    if is_reindexing() or not _has_embedding_index():
        return

    _poll_progress.running = True
    _poll_progress.total = 0
    _poll_progress.done = 0
    _poll_progress.succeeded = 0
    _poll_progress.failed = 0
    _poll_progress.skipped = 0
    _poll_progress.phase = "prepare"
    _poll_progress.phase_done = 0
    _poll_progress.phase_total = 0
    _poll_progress.cancelled = False
    _poll_progress.error = None
    _poll_progress.started_at = datetime.now(tz=UTC).isoformat()
    _poll_progress.cycle_id = None
    try:
        await poll_inbox()
    except Exception as exc:
        _poll_progress.error = str(exc)
        log.error("scheduled poll failed", error=str(exc))
    finally:
        _poll_progress.running = False


def start_scheduler(app: object) -> None:
    """Initialise and start the APScheduler."""
    set_clients(
        getattr(app, "state", app).paperless,  # type: ignore[union-attr]
        getattr(app, "state", app).ollama,  # type: ignore[union-attr]
    )

    if settings.poll_interval_seconds <= 0:
        log.info("automatic polling disabled (poll_interval_seconds=0)")
        return

    scheduler = AsyncIOScheduler()
    scheduler.add_job(
        _scheduled_poll,
        "interval",
        seconds=settings.poll_interval_seconds,
        id="poll_inbox",
        replace_existing=True,
    )
    scheduler.start()
    app.state.scheduler = scheduler  # type: ignore[union-attr]
    log.info("scheduler started", interval=settings.poll_interval_seconds)


def stop_scheduler(app: object) -> None:
    """Shutdown the APScheduler gracefully."""
    scheduler = getattr(getattr(app, "state", None), "scheduler", None)
    if scheduler:
        scheduler.shutdown(wait=False)
        log.info("scheduler stopped")
