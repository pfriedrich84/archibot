#!/usr/bin/env python3
"""Local smoke checks for the event-driven Archibot migration.

This script is intentionally dependency-light: it verifies that the event-driven
Python contracts import and that queue names/config-derived defaults are sane.
It does not require live PostgreSQL, RabbitMQ, Paperless or Ollama services.
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
    "app.event_worker",
    "app.jobs.actor_execution",
    "app.jobs.document_embeddings",
    "app.jobs.embedding_gate",
    "app.jobs.embedding_index",
    "app.jobs.pipeline_items",
    "app.jobs.pipeline_runs",
    "app.jobs.progress",
    "app.jobs.recovery",
    "app.jobs.review_commit",
    "app.jobs.review_suggestions",
    "app.jobs.webhook_delivery",
]


def main() -> int:
    from app.config import settings
    from app.dramatiq_broker import queue_name

    for module in MODULES:
        importlib.import_module(module)

    expected_prefix = settings.archibot_queue_prefix
    queues = [queue_name(name) for name in ["webhook", "io", "embedding", "blocking"]]
    if not all(name.startswith(expected_prefix + ".") for name in queues):
        raise SystemExit(f"queue prefix mismatch: {queues!r}")

    if settings.poll_interval_seconds < 0:
        raise SystemExit("poll_interval_seconds must not be negative")

    print("event-driven smoke checks passed")
    print("queues=" + ",".join(queues))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
