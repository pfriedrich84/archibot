"""Retired Absurd worker compatibility entry point.

Laravel database queues are the only productive actor transport. The sole
remaining Python operation is a transition-only recovery scan through the deep
execution lifecycle facade; it never enqueues work.
"""

from __future__ import annotations

import argparse
import time

import structlog

from app import execution_lifecycle
from app.config import assert_product_database_config
from app.jobs.recovery import run_recovery_scan

log = structlog.get_logger(__name__)


def enqueue_poll_reconciliation(limit: int | None = None) -> None:
    execution_lifecycle.retired_python_dispatch("periodic poll enqueue")


def run_recovery_loop(
    *, interval_seconds: int | None = None, once: bool = False, limit: int = 100
) -> None:
    recovery_interval = 30 if interval_seconds is None else interval_seconds
    while True:
        run_recovery_scan(limit=limit)
        if once:
            return
        sleep_seconds = max(1, recovery_interval)
        log.info("transition-only recovery loop sleeping", interval_seconds=sleep_seconds)
        time.sleep(sleep_seconds)


def start_queue_workers(*, concurrency: int = 1, claim_timeout: int = 120) -> None:
    execution_lifecycle.retired_python_dispatch("Absurd queue worker startup")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="ArchiBot transition-only recovery utility")
    subparsers = parser.add_subparsers(dest="command", required=True)
    recovery = subparsers.add_parser("recovery-scan", help="Repair stale durable transitions")
    recovery.add_argument("--once", action="store_true", help="Run one scan and exit")
    recovery.add_argument("--interval-seconds", type=int, default=None)
    recovery.add_argument("--limit", type=int, default=100)
    return parser


def main(argv: list[str] | None = None) -> int:
    assert_product_database_config()
    args = build_parser().parse_args(argv)
    if args.command == "recovery-scan":
        run_recovery_loop(interval_seconds=args.interval_seconds, once=args.once, limit=args.limit)
        return 0
    raise ValueError(f"Unsupported command: {args.command}")


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())
