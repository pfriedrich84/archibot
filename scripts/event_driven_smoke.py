#!/usr/bin/env python3
"""Local smoke checks for the event-driven Archibot migration.

This script is intentionally dependency-light: it verifies that the event-driven
Python contracts import and that the fixed Laravel actor boundary is available.
It does not require live PostgreSQL, Paperless or AI-provider services.
"""

from __future__ import annotations

import importlib
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

MODULES = [
    "app.actors.document",
    "app.actors.embedding",
    "app.actors.maintenance",
    "app.actors.review",
    "app.actors.webhook",
    "app.actor_runner",
    "app.jobs.actor_execution",
    "app.jobs.document_embeddings",
    "app.jobs.embedding_gate",
    "app.jobs.embedding_index",
    "app.jobs.pipeline_items",
    "app.jobs.pipeline_runs",
    "app.jobs.progress",
    "app.jobs.review_commit",
    "app.jobs.review_suggestions",
    "app.jobs.webhook_delivery",
]


def main() -> int:
    from app.actors import LARAVEL_DATABASE_QUEUE
    from app.config import settings

    for module in MODULES:
        importlib.import_module(module)

    if LARAVEL_DATABASE_QUEUE != "laravel.database":
        raise SystemExit(f"unexpected actor transport label: {LARAVEL_DATABASE_QUEUE!r}")

    if settings.poll_interval_seconds < 0:
        raise SystemExit("poll_interval_seconds must not be negative")

    print("event-driven smoke checks passed")
    print("transport=" + LARAVEL_DATABASE_QUEUE)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
