"""Operator CLI delegating every product action to Laravel/PostgreSQL.

Python actor execution has a separate fixed entry point (``app.actor_runner``).
"""

from __future__ import annotations

import logging
import subprocess
import sys
from pathlib import Path

import structlog

from app.config import assert_product_database_config, settings

COMMANDS: dict[str, str] = {
    "reindex": "Queue full reindex through Laravel Maintenance",
    "reindex-ocr": "Queue OCR reindex through Laravel Maintenance (--force supported)",
    "reindex-embed": "Queue embedding build through Laravel Maintenance",
    "poll": "Queue poll reconciliation through Laravel Maintenance (--force supported)",
    "process-doc": "Queue one document pipeline through Laravel Maintenance",
    "process-document": "Queue one document pipeline through Laravel Maintenance",
    "commit-review": "Accept and queue a durable Laravel review suggestion commit",
    "reset": "Reset via Laravel/PostgreSQL (--yes required)",
}


def _configure_logging() -> None:
    log_level = getattr(logging, settings.log_level.upper(), logging.INFO)
    structlog.configure(
        processors=[
            structlog.contextvars.merge_contextvars,
            structlog.processors.add_log_level,
            structlog.processors.TimeStamper(fmt="iso"),
            structlog.dev.ConsoleRenderer(colors=False),
        ],
        wrapper_class=structlog.make_filtering_bound_logger(log_level),
        context_class=dict,
        logger_factory=structlog.PrintLoggerFactory(),
        cache_logger_on_first_use=True,
    )


def _laravel_artisan_path() -> Path | None:
    candidates = [
        Path("/app/laravel/artisan"),
        Path(__file__).resolve().parents[1] / "laravel" / "artisan",
    ]
    return next((path for path in candidates if path.exists()), None)


def _run_artisan(arguments: list[str]) -> None:
    artisan = _laravel_artisan_path()
    if artisan is None:
        print(
            "This action is Laravel/PostgreSQL-owned, but Laravel artisan was not found.",
            file=sys.stderr,
        )
        raise SystemExit(1)

    result = subprocess.run(["php", str(artisan), *arguments], cwd=artisan.parent, check=False)
    if result.returncode != 0:
        raise SystemExit(result.returncode)


def cmd_laravel_maintenance(
    command_type: str,
    *,
    force: bool = False,
    document_id: int | None = None,
    limit: int | None = None,
) -> None:
    arguments = ["archibot:maintenance-command", command_type]
    if force:
        arguments.append("--force")
    if document_id is not None:
        arguments.append(f"--document-id={document_id}")
    if limit is not None:
        arguments.append(f"--limit={limit}")
    _run_artisan(arguments)


def cmd_reset(include_config: bool = False) -> None:
    arguments = ["archibot:reset", "--yes"]
    if include_config:
        arguments.append("--include-config")
    _run_artisan(arguments)


def cmd_commit_review(suggestion_id: int, user_id: int) -> None:
    _run_artisan(["archibot:review-commit", str(suggestion_id), f"--user-id={user_id}"])


def _reject_unknown_args(
    args: list[str], allowed_flags: set[str], allowed_prefixes: tuple[str, ...] = ()
) -> None:
    unknown = [
        arg
        for arg in args
        if arg not in allowed_flags
        and not any(arg.startswith(prefix) for prefix in allowed_prefixes)
    ]
    if unknown:
        print(f"Unsupported argument(s): {' '.join(unknown)}")
        raise SystemExit(1)


def _positive_int(raw: str, label: str) -> int:
    try:
        value = int(raw)
    except ValueError:
        print(f"Invalid {label}: {raw}")
        raise SystemExit(1) from None
    if value < 1:
        print(f"Invalid {label}: {raw}")
        raise SystemExit(1)
    return value


def main() -> None:
    assert_product_database_config()
    if len(sys.argv) < 2 or sys.argv[1] in ("-h", "--help"):
        print("Usage: python -m app.cli <command>\n")
        print("Commands:")
        for name, description in COMMANDS.items():
            print(f"  {name:<20} {description}")
        raise SystemExit(0 if len(sys.argv) >= 2 else 1)

    command = sys.argv[1]
    if command not in COMMANDS:
        print(f"Unknown command: {command}")
        print(f"Available: {', '.join(COMMANDS)}")
        raise SystemExit(1)

    _configure_logging()
    args = sys.argv[2:]
    force = "--force" in args
    limit_value = next((arg.split("=", 1)[1] for arg in args if arg.startswith("--limit=")), None)
    limit = _positive_int(limit_value, "limit") if limit_value is not None else None

    if command == "reset":
        _reject_unknown_args(args, {"--yes", "--include-config"})
        if "--yes" not in args:
            print("Safety check: pass --yes to confirm reset.")
            raise SystemExit(1)
        cmd_reset(include_config="--include-config" in args)
        return

    if command == "commit-review":
        positional = [arg for arg in args if not arg.startswith("-")]
        user_value = next(
            (arg.split("=", 1)[1] for arg in args if arg.startswith("--user-id=")),
            None,
        )
        _reject_unknown_args(args, set(positional), ("--user-id=",))
        if len(positional) != 1 or user_value is None:
            print("Usage: archibot commit-review <review_suggestion_id> --user-id=<id>")
            raise SystemExit(1)
        cmd_commit_review(
            _positive_int(positional[0], "review_suggestion_id"),
            _positive_int(user_value, "user_id"),
        )
        return

    if command in {"process-doc", "process-document"}:
        positional = [arg for arg in args if not arg.startswith("-")]
        _reject_unknown_args(args, {"--force", *positional})
        if len(positional) != 1:
            print("Usage: archibot process-doc <document_id> [--force]")
            raise SystemExit(1)
        cmd_laravel_maintenance(
            "process_document",
            force=force,
            document_id=_positive_int(positional[0], "document_id"),
        )
        return

    allowed_flags = {"--force"} if command in {"poll", "reindex-ocr"} else set()
    _reject_unknown_args(args, allowed_flags, ("--limit=",))
    maintenance_type = {
        "poll": "poll",
        "reindex": "reindex",
        "reindex-ocr": "reindex_ocr",
        "reindex-embed": "reindex_embed",
    }[command]
    cmd_laravel_maintenance(maintenance_type, force=force, limit=limit)


if __name__ == "__main__":
    main()
