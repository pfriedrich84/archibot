"""APScheduler-based background worker for inbox polling and classification."""

from __future__ import annotations

import asyncio
from dataclasses import dataclass
from datetime import UTC, datetime

import structlog
from apscheduler.schedulers.asyncio import AsyncIOScheduler

from app.clients.ollama import OllamaClient
from app.clients.paperless import PaperlessClient
from app.config import settings
from app.db import get_conn
from app.indexer import is_reindexing
from app.pipeline.ocr_correction import configured_ocr_tag_exists, ocr_requested_tag_id

log = structlog.get_logger(__name__)

# Module-level refs set by start_scheduler
_paperless: PaperlessClient | None = None
_ollama: OllamaClient | None = None


def set_clients(paperless: PaperlessClient | None, ollama: OllamaClient | None) -> None:
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
    cancelled: bool = False
    error: str | None = None
    started_at: str | None = None  # ISO timestamp when this poll started
    cycle_id: str | None = None  # links to poll_cycles table


_poll_progress = PollProgress()
_poll_task: asyncio.Task | None = None


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
    """Return ``True`` if an index run completed successfully and embeddings exist.

    Checks two conditions:
    1. ``audit_log`` contains an ``index_complete`` entry (persistent marker)
    2. ``doc_embedding_meta`` actually has entries (tables are populated)
    """
    with get_conn() as conn:
        completed = conn.execute(
            "SELECT 1 FROM audit_log WHERE action = 'index_complete' LIMIT 1"
        ).fetchone()
        if not completed:
            return False
        count = conn.execute("SELECT COUNT(*) AS c FROM doc_embedding_meta").fetchone()
    return count["c"] > 0


def start_poll_task() -> bool:
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
    _poll_progress.cancelled = False
    _poll_progress.error = None
    _poll_progress.started_at = datetime.now(tz=UTC).isoformat()
    _poll_progress.cycle_id = None

    async def _run() -> None:
        try:
            await poll_inbox()
        except Exception as exc:
            _poll_progress.error = str(exc)
            log.error("background poll failed", error=str(exc))
        finally:
            _poll_progress.running = False

    global _poll_task
    _poll_task = asyncio.create_task(_run())
    return True


# ---------------------------------------------------------------------------
# Main poll loop
# ---------------------------------------------------------------------------
async def poll_inbox(*, force: bool = False) -> None:
    """Fetch inbox documents and run the classification pipeline.

    Processing is split into phases to minimise Ollama model swaps:

    1. **OCR correction** (chat model, optional) — all docs
    2. **Embedding + context search** (embed model) — all docs
    3. **Classification + post-processing** (chat model) — all docs

    Each phase unloads its model from VRAM before the next phase begins.

    When ``force=True``, the idempotency skip check is bypassed and inbox
    documents are reprocessed even if their ``modified`` timestamp did not change.
    """
    if _paperless is None or _ollama is None:
        log.error("worker not initialised — skipping poll")
        return

    if is_reindexing():
        log.info("reindex in progress — skipping poll")
        return

    if not _has_embedding_index():
        log.info("no embedding index yet — skipping poll (run reindex first)")
        return

    log.info("polling inbox")
    try:
        docs = await _paperless.list_inbox_documents(settings.paperless_inbox_tag_id)
    except Exception as exc:
        log.error("failed to fetch inbox", error=str(exc))
        _write_error("poll", None, exc)
        return

    if not docs:
        log.info("inbox empty")
        return

    # Cache entity lists once per cycle
    try:
        correspondents = await _paperless.list_correspondents()
        doctypes = await _paperless.list_document_types()
        storage_paths = await _paperless.list_storage_paths()
        tags = await _paperless.list_tags()
    except Exception as exc:
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
