"""CLI management commands for manual pipeline triggering.

Usage::

    python -m app.cli reindex          # Full reindex (OCR + embedding)
    python -m app.cli reindex-ocr      # OCR correction only (skip cached)
    python -m app.cli reindex-ocr --force  # OCR correction, ignore cache
    python -m app.cli reindex-embed    # Embedding only (skip OCR)
    python -m app.cli poll             # Process inbox (OCR + embed + classify)
    python -m app.cli poll --force     # Reprocess inbox docs (ignore idempotency skip)
    python -m app.cli process-doc 224  # Process one document by ID
    python -m app.cli process-doc 224 --force  # Reprocess one document
    python -m app.cli reset --yes      # Delete DB and recreate clean schema
    python -m app.cli reset --yes --include-config  # Also delete config.env
"""

from __future__ import annotations

import asyncio
import contextlib
import json
import logging
import signal
import sqlite3
import sys
import uuid
from pathlib import Path
from typing import Any

import structlog

from app.clients.ollama import OllamaClient
from app.clients.paperless import PaperlessClient
from app.config import settings
from app.db import init_db, mark_setup_required


def _configure_logging() -> None:
    """Set up structlog for CLI use (always console renderer)."""
    log_level = getattr(logging, settings.log_level.upper(), logging.INFO)
    structlog.configure(
        processors=[
            structlog.contextvars.merge_contextvars,
            structlog.processors.add_log_level,
            structlog.processors.StackInfoRenderer(),
            structlog.dev.set_exc_info,
            structlog.processors.TimeStamper(fmt="iso"),
            structlog.dev.ConsoleRenderer(),
        ],
        wrapper_class=structlog.make_filtering_bound_logger(log_level),
        context_class=dict,
        logger_factory=structlog.PrintLoggerFactory(),
        cache_logger_on_first_use=True,
    )


async def cmd_reindex() -> None:
    """Full reindex: OCR correction (if enabled) + embedding."""
    from app.indexer import reindex_all

    paperless = PaperlessClient()
    ollama = OllamaClient()
    try:
        count = await reindex_all(paperless, ollama)
        print(f"Reindex complete: {count} documents indexed.")
    finally:
        await paperless.aclose()
        await ollama.aclose()


async def cmd_reindex_ocr(*, force: bool = False) -> None:
    """Run OCR correction on all Paperless documents (respects OCR_MODE)."""
    from app.pipeline.ocr_correction import batch_correct_documents, effective_ocr_mode

    mode = effective_ocr_mode()
    if mode == "off":
        print("OCR_MODE is 'off' — nothing to do. Set OCR_MODE to text/vision_light/vision_full.")
        return

    paperless = PaperlessClient()
    ollama = OllamaClient()
    try:
        corrected = await batch_correct_documents(paperless, ollama, force=force)
        print(f"OCR correction complete: {corrected} documents corrected (mode={mode}).")
    finally:
        await paperless.aclose()
        await ollama.aclose()


async def cmd_reindex_embed() -> None:
    """Rebuild embeddings only (skip OCR, use cached OCR text if available)."""
    from app.db import EMBED_DIM, get_conn
    from app.indexer import initial_index

    # Drop + recreate vec0 so dimension changes take effect, also clear FTS index
    with get_conn() as conn:
        conn.execute("DELETE FROM doc_embedding_meta")
        conn.execute("DROP TABLE IF EXISTS doc_embeddings")
        conn.execute(
            f"""CREATE VIRTUAL TABLE doc_embeddings USING vec0(
                document_id INTEGER PRIMARY KEY,
                embedding   FLOAT[{EMBED_DIM}]
            )"""
        )
        conn.execute("DELETE FROM doc_fts")
    print("Cleared existing embeddings and FTS index.")

    paperless = PaperlessClient()
    ollama = OllamaClient()
    try:
        count = await initial_index(paperless, ollama)
        print(f"Embedding complete: {count} documents indexed.")
    finally:
        await paperless.aclose()
        await ollama.aclose()


async def cmd_poll(*, force: bool = False) -> None:
    """Process inbox: OCR + embed + classify (same as scheduled poll).

    With ``force=True`` the idempotency skip check is bypassed.
    """
    from app.job_events import list_events, record_event
    from app.worker import poll_inbox

    paperless = PaperlessClient()
    ollama = OllamaClient()

    # The worker needs module-level client refs — set them via start_scheduler's pattern
    import app.worker as worker

    worker._paperless = paperless
    worker._ollama = ollama
    worker._poll_progress.job_type = "poll"
    worker._poll_progress.job_id = f"cli-poll-{uuid.uuid4().hex[:12]}"
    record_event(
        worker._poll_progress.job_id,
        "poll",
        "job_started",
        "CLI-Posteingang-Prüfung gestartet.",
        phase="prepare",
    )

    # Wire Ctrl+C to the worker's cooperative cancellation flag
    loop = asyncio.get_running_loop()
    loop.add_signal_handler(
        signal.SIGINT,
        lambda: (
            setattr(worker._poll_progress, "cancelled", True),
            print("\nInterrupting after current document… (press Ctrl+C again to force)"),
            loop.remove_signal_handler(signal.SIGINT),
        ),
    )

    try:
        await poll_inbox(force=force)
        for event in list_events(worker._poll_progress.job_id or "", limit=1000):
            doc = f" doc=#{event['document_id']}" if event.get("document_id") else ""
            print(
                f"[{event['level']}] {event.get('phase') or event['job_type']}{doc}: {event['message']}"
            )
        if worker._poll_progress.cancelled:
            print("Inbox processing cancelled.")
        else:
            print("Inbox processing complete.")
    finally:
        await paperless.aclose()
        await ollama.aclose()


def _coerce_tag_ids(values: list[object]) -> list[int]:
    tag_ids: list[int] = []
    for value in values:
        try:
            if value is not None:
                tag_ids.append(int(value))
        except (TypeError, ValueError):
            continue
    return tag_ids


async def cmd_process_doc(document_id: int, *, force: bool = False) -> str:
    """Process exactly one document by ID (OCR + embed + classify)."""
    from app.db import get_conn
    from app.pipeline.document_processing import process_document

    paperless = PaperlessClient()
    ollama = OllamaClient()
    try:
        doc = await paperless.get_document(document_id)
        correspondents = await paperless.list_correspondents()
        doctypes = await paperless.list_document_types()
        storage_paths = await paperless.list_storage_paths()
        tags = await paperless.list_tags()

        if force:
            with get_conn() as conn:
                conn.execute(
                    "DELETE FROM processed_documents WHERE document_id = ?", (document_id,)
                )

        result = await process_document(
            doc,
            paperless,
            ollama,
            correspondents,
            doctypes,
            storage_paths,
            tags,
        )
        print(f"Document #{document_id} processing complete: {result}")
        return result
    finally:
        await paperless.aclose()
        await ollama.aclose()


async def cmd_sync_entity_approval(
    action: str, entity_type: str, name: str, paperless_id: int | None = None
) -> dict[str, object]:
    """Synchronize a Laravel-owned entity approval decision into the Python worker DB."""
    from app.db import get_conn
    from app.pipeline.committer import (
        retroactive_correspondent_apply,
        retroactive_doctype_apply,
        retroactive_tag_apply,
    )

    tables = {
        "tag": ("tag_whitelist", "tag_blacklist", retroactive_tag_apply),
        "correspondent": (
            "correspondent_whitelist",
            "correspondent_blacklist",
            retroactive_correspondent_apply,
        ),
        "document_type": ("doctype_whitelist", "doctype_blacklist", retroactive_doctype_apply),
    }
    if entity_type not in tables:
        raise ValueError(f"Unsupported entity approval type: {entity_type}")
    if action not in {"approved", "rejected", "unblacklisted"}:
        raise ValueError(f"Unsupported entity approval action: {action}")

    whitelist_table, blacklist_table, retroactive_apply = tables[entity_type]
    result: dict[str, object] = {
        "action": action,
        "type": entity_type,
        "name": name,
        "synced": True,
    }

    if action == "approved":
        if paperless_id is None:
            raise ValueError("Approved entity sync requires paperless_id")
        with get_conn() as conn:
            conn.execute(f"DELETE FROM {blacklist_table} WHERE name = ?", (name,))
            conn.execute(
                f"""
                INSERT INTO {whitelist_table} (name, paperless_id, approved)
                VALUES (?, ?, 1)
                ON CONFLICT(name) DO UPDATE SET paperless_id = excluded.paperless_id, approved = 1
                """,
                (name, paperless_id),
            )
            conn.execute(
                """
                INSERT INTO audit_log (action, document_id, actor, details)
                VALUES ('laravel_entity_approval_synced', NULL, 'laravel', ?)
                """,
                (
                    json.dumps(
                        {
                            "action": action,
                            "type": entity_type,
                            "name": name,
                            "paperless_id": paperless_id,
                        }
                    ),
                ),
            )

        paperless = PaperlessClient()
        try:
            patched, pending = await retroactive_apply(name, paperless_id, paperless)
        finally:
            await paperless.aclose()
        result.update(
            {"paperless_id": paperless_id, "patched_docs": patched, "updated_pending": pending}
        )
    elif action == "rejected":
        with get_conn() as conn:
            row = conn.execute(
                f"SELECT times_seen FROM {whitelist_table} WHERE name = ?", (name,)
            ).fetchone()
            times_seen = row["times_seen"] if row else 1
            conn.execute(f"DELETE FROM {whitelist_table} WHERE name = ?", (name,))
            conn.execute(
                f"INSERT OR REPLACE INTO {blacklist_table} (name, times_seen) VALUES (?, ?)",
                (name, times_seen),
            )
    else:
        with get_conn() as conn:
            conn.execute(f"DELETE FROM {blacklist_table} WHERE name = ?", (name,))

    print(f"Entity approval sync complete: {result}")
    return result


async def cmd_commit_review(suggestion_id: int) -> dict[str, object]:
    """Commit an accepted Python-origin review suggestion to Paperless."""
    from app.db import get_conn
    from app.models import ReviewDecision, SuggestionRow
    from app.pipeline.committer import commit_suggestion

    with get_conn() as conn:
        row = conn.execute("SELECT * FROM suggestions WHERE id = ?", (suggestion_id,)).fetchone()

    if row is None:
        raise ValueError(f"Suggestion #{suggestion_id} not found")

    suggestion = SuggestionRow(**dict(row))
    proposed_tags = _decode_json_value(suggestion.proposed_tags_json, [])
    tag_ids = _coerce_tag_ids(
        [tag.get("id") for tag in proposed_tags if isinstance(tag, dict)]
        if isinstance(proposed_tags, list)
        else []
    )
    decision = ReviewDecision(
        suggestion_id=suggestion.id,
        title=suggestion.proposed_title or suggestion.original_title or "",
        date=suggestion.effective_date,
        correspondent_id=suggestion.effective_correspondent_id,
        doctype_id=suggestion.effective_doctype_id,
        storage_path_id=suggestion.effective_storage_path_id,
        tag_ids=tag_ids,
        action="accept",
    )

    paperless = PaperlessClient()
    try:
        await commit_suggestion(suggestion, decision, paperless)
    finally:
        await paperless.aclose()

    with get_conn() as conn:
        updated = conn.execute(
            "SELECT status FROM suggestions WHERE id = ?", (suggestion_id,)
        ).fetchone()

    status = updated["status"] if updated else "error"
    result = {
        "source_suggestion_id": suggestion_id,
        "status": status,
        "committed": status == "committed",
    }
    print(f"Suggestion #{suggestion_id} commit complete: {result}")
    return result


def cmd_reset(include_config: bool = False) -> None:
    """Delete all persistent state and recreate a clean database."""
    log = structlog.get_logger("reset")
    data_dir = Path(settings.data_dir)
    db_path = settings.db_path

    # Build file list
    targets: list[Path] = [
        db_path,
        db_path.parent / f"{db_path.name}-wal",
        db_path.parent / f"{db_path.name}-shm",
    ]

    if include_config:
        targets.append(data_dir / "config.env")
        targets.extend(data_dir.glob("config.bak.*"))

    # Only existing files
    existing = [p for p in targets if p.exists()]

    if existing:
        print("Deleting:")
        for p in existing:
            print(f"  {p}")
    else:
        print("No existing state files found.")

    for p in existing:
        p.unlink()
        log.info("deleted", path=str(p))

    # Recreate clean DB and force onboarding on next app start.
    init_db()
    if db_path.exists():
        try:
            mark_setup_required()
        except sqlite3.Error as exc:
            log.warning("could not persist setup-required marker", error=str(exc))
    print(f"Reset complete. Clean database created at {db_path}")


COMMANDS = {
    "reindex": ("Full reindex (OCR + embedding)", cmd_reindex),
    "reindex-ocr": ("OCR correction only (--force to ignore cache)", cmd_reindex_ocr),
    "reindex-embed": ("Rebuild embeddings only", cmd_reindex_embed),
    "poll": ("Process inbox (OCR + embed + classify, --force to reprocess)", cmd_poll),
    "process-doc": ("Process a single document by ID (optional --force)", cmd_process_doc),
    "process-document": (
        "Process a single document by ID via Laravel worker contract",
        cmd_process_doc,
    ),
    "commit-review": (
        "Commit an accepted review suggestion by Python suggestion ID",
        cmd_commit_review,
    ),
    "sync-entity-approval": (
        "Synchronize a Laravel entity approval decision into the Python worker DB",
        cmd_sync_entity_approval,
    ),
    "reset": ("Delete all state and recreate empty DB (--yes required)", None),
}


def _arg_value(args: list[str], name: str) -> str | None:
    """Return the value following *name* in CLI args, if present."""
    if name not in args:
        return None
    idx = args.index(name)
    if idx + 1 >= len(args):
        return None
    return args[idx + 1]


def _load_worker_contract(extra_args: list[str]) -> tuple[dict[str, object], Path] | None:
    """Load Laravel worker JSON contract input/output paths from CLI args."""
    input_path = _arg_value(extra_args, "--input")
    output_path = _arg_value(extra_args, "--output")

    if input_path is None and output_path is None:
        return None
    if not input_path or not output_path:
        print("Worker JSON contract requires both --input and --output")
        sys.exit(1)

    with Path(input_path).open("r", encoding="utf-8") as fh:
        payload = json.load(fh)

    if not isinstance(payload, dict):
        print("Worker JSON contract input must be a JSON object")
        sys.exit(1)

    return payload, Path(output_path)


def _write_worker_output(output_path: Path, payload: dict[str, object]) -> None:
    """Write a JSON result for Laravel worker job ingestion."""
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False, indent=2, default=str)


def _decode_json_value(value: str | None, default: Any = None) -> Any:
    """Decode a JSON string from the legacy Python DB, falling back safely."""
    if value is None or value == "":
        return default
    with contextlib.suppress(json.JSONDecodeError, TypeError):
        return json.loads(value)
    return {"text": value}


def _suggestion_row_to_review_suggestion(row: Any) -> dict[str, object]:
    """Map a Python suggestion row to Laravel's stable review ingestion shape."""
    return {
        "source_suggestion_id": row.id,
        "python_suggestion_id": row.id,
        "paperless_document_id": row.document_id,
        "status": row.status,
        "confidence": row.confidence,
        "reasoning": row.reasoning,
        "original": {
            "title": row.original_title,
            "date": row.original_date,
            "correspondent_id": row.original_correspondent,
            "document_type_id": row.original_doctype,
            "storage_path_id": row.original_storage_path,
            "tags": _decode_json_value(row.original_tags_json, []),
        },
        "proposed": {
            "title": row.proposed_title,
            "date": row.proposed_date,
            "correspondent_name": row.proposed_correspondent_name,
            "correspondent_id": row.proposed_correspondent_id,
            "document_type_name": row.proposed_doctype_name,
            "document_type_id": row.proposed_doctype_id,
            "storage_path_name": row.proposed_storage_path_name,
            "storage_path_id": row.proposed_storage_path_id,
            "tags": _decode_json_value(row.proposed_tags_json, []),
        },
        "context_documents": _decode_json_value(row.context_docs_json, []),
        "raw_response": _decode_json_value(row.raw_response),
        "judge_verdict": row.judge_verdict,
        "judge_reasoning": row.judge_reasoning,
        "original_proposed_snapshot": _decode_json_value(row.original_proposed_json),
    }


def _latest_suggestion_id() -> int:
    """Return the current highest Python suggestion id for delta-based worker output."""
    from app.db import get_conn

    with get_conn() as conn:
        row = conn.execute("SELECT COALESCE(MAX(id), 0) AS max_id FROM suggestions").fetchone()

    return int(row["max_id"] or 0)


def _review_suggestion_payloads_since(
    suggestion_id: int, *, document_id: int | None = None
) -> list[dict[str, object]]:
    """Return Python suggestions created after *suggestion_id* in Laravel ingest format."""
    from app.db import get_conn
    from app.models import SuggestionRow

    sql = "SELECT * FROM suggestions WHERE id > ?"
    params: list[object] = [suggestion_id]
    if document_id is not None:
        sql += " AND document_id = ?"
        params.append(document_id)
    sql += " ORDER BY id ASC"

    with get_conn() as conn:
        rows = conn.execute(sql, params).fetchall()

    return [_suggestion_row_to_review_suggestion(SuggestionRow(**dict(row))) for row in rows]


def _contract_payload(input_payload: dict[str, object]) -> dict[str, object]:
    payload = input_payload.get("payload", {})
    return payload if isinstance(payload, dict) else {}


def _contract_document_id(input_payload: dict[str, object]) -> int | None:
    payload = _contract_payload(input_payload)
    raw = payload.get("paperless_document_id") or payload.get("document_id")
    if raw is None:
        return None
    try:
        return int(raw)
    except (TypeError, ValueError):
        return None


def _contract_entity_approval(input_payload: dict[str, object]) -> tuple[str, str, str, int | None]:
    payload = _contract_payload(input_payload)
    action = payload.get("action")
    entity_type = payload.get("type")
    name = payload.get("name")
    raw_paperless_id = payload.get("paperless_id")

    if not isinstance(action, str) or not isinstance(entity_type, str) or not isinstance(name, str):
        raise ValueError("Worker payload requires action, type, and name for sync-entity-approval")

    paperless_id = None
    if raw_paperless_id is not None:
        try:
            paperless_id = int(raw_paperless_id)
        except (TypeError, ValueError):
            raise ValueError("paperless_id must be numeric when provided") from None

    return action, entity_type, name, paperless_id


def _contract_source_suggestion_id(input_payload: dict[str, object]) -> int | None:
    payload = _contract_payload(input_payload)
    raw = payload.get("source_suggestion_id") or payload.get("suggestion_id")
    if raw is None:
        return None
    try:
        return int(raw)
    except (TypeError, ValueError):
        return None


def _contract_force(input_payload: dict[str, object], cli_force: bool) -> bool:
    payload = _contract_payload(input_payload)
    raw = payload.get("force")
    if isinstance(raw, bool):
        return cli_force or raw
    if isinstance(raw, str):
        return cli_force or raw.lower() in {"1", "true", "yes"}
    return cli_force


def main() -> None:
    if len(sys.argv) < 2 or sys.argv[1] in ("-h", "--help"):
        print("Usage: python -m app.cli <command>\n")
        print("Commands:")
        for name, (desc, _) in COMMANDS.items():
            print(f"  {name:<20} {desc}")
        sys.exit(0 if len(sys.argv) >= 2 else 1)

    cmd_name = sys.argv[1]
    if cmd_name not in COMMANDS:
        print(f"Unknown command: {cmd_name}")
        print(f"Available: {', '.join(COMMANDS)}")
        sys.exit(1)

    _configure_logging()

    # reset is synchronous and must NOT call init_db() before deletion
    if cmd_name == "reset":
        extra_args = sys.argv[2:]
        if "--yes" not in extra_args:
            print("Safety check: pass --yes to confirm reset.")
            print("  archibot reset --yes")
            print("  archibot reset --yes --include-config")
            sys.exit(1)
        cmd_reset(include_config="--include-config" in extra_args)
        return

    init_db()

    extra_args = sys.argv[2:]
    force = "--force" in extra_args

    _, cmd_func = COMMANDS[cmd_name]
    contract = _load_worker_contract(extra_args)
    try:
        if contract is not None:
            input_payload, output_path = contract
            output_payload: dict[str, object] = {
                "ok": True,
                "command": cmd_name,
                "job_id": input_payload.get("id"),
                "type": input_payload.get("type", cmd_name),
            }
            if cmd_name == "poll":
                before_suggestion_id = _latest_suggestion_id()
                asyncio.run(cmd_func(force=_contract_force(input_payload, force)))
                review_suggestions = _review_suggestion_payloads_since(before_suggestion_id)
                if review_suggestions:
                    output_payload["review_suggestions"] = review_suggestions
            elif cmd_name == "reindex":
                asyncio.run(cmd_func())
            elif cmd_name in {"process-doc", "process-document"}:
                document_id = _contract_document_id(input_payload)
                if document_id is None:
                    raise ValueError(
                        "Worker payload requires paperless_document_id for process-document"
                    )
                before_suggestion_id = _latest_suggestion_id()
                result = asyncio.run(
                    cmd_func(document_id, force=_contract_force(input_payload, force))
                )
                output_payload["result"] = result
                if result in {"classified", "auto_committed"}:
                    review_suggestions = _review_suggestion_payloads_since(
                        before_suggestion_id, document_id=document_id
                    )
                    if review_suggestions:
                        output_payload["review_suggestions"] = review_suggestions
            elif cmd_name == "commit-review":
                suggestion_id = _contract_source_suggestion_id(input_payload)
                if suggestion_id is None:
                    raise ValueError(
                        "Worker payload requires source_suggestion_id for commit-review"
                    )
                output_payload["result"] = asyncio.run(cmd_func(suggestion_id))
            elif cmd_name == "sync-entity-approval":
                output_payload["result"] = asyncio.run(
                    cmd_func(*_contract_entity_approval(input_payload))
                )
            else:
                asyncio.run(cmd_func())
            _write_worker_output(output_path, output_payload)
        elif cmd_name in {"reindex-ocr", "poll"}:
            asyncio.run(cmd_func(force=force))
        elif cmd_name in {"process-doc", "process-document"}:
            doc_arg = next((a for a in extra_args if not a.startswith("-")), None)
            if doc_arg is None:
                print("Usage: archibot process-doc <document_id> [--force]")
                sys.exit(1)
            try:
                document_id = int(doc_arg)
            except ValueError:
                print(f"Invalid document_id: {doc_arg}")
                sys.exit(1)
            asyncio.run(cmd_func(document_id, force=force))
        elif cmd_name == "commit-review":
            suggestion_arg = next((a for a in extra_args if not a.startswith("-")), None)
            if suggestion_arg is None:
                print("Usage: archibot commit-review <source_suggestion_id>")
                sys.exit(1)
            try:
                suggestion_id = int(suggestion_arg)
            except ValueError:
                print(f"Invalid source_suggestion_id: {suggestion_arg}")
                sys.exit(1)
            asyncio.run(cmd_func(suggestion_id))
        elif cmd_name == "sync-entity-approval":
            print("Usage: archibot sync-entity-approval --input <path> --output <path>")
            sys.exit(1)
        else:
            asyncio.run(cmd_func())
    except KeyboardInterrupt:
        print("\nAborted.")
        sys.exit(130)
    except Exception as exc:
        if contract is not None:
            _, output_path = contract
            _write_worker_output(output_path, {"ok": False, "command": cmd_name, "error": str(exc)})
        raise


if __name__ == "__main__":
    main()
