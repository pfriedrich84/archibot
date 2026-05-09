"""Event-driven worker bootstrap helpers.

This module is intentionally small: Dramatiq workers execute actors, while the
recovery loop periodically bridges durable queued PostgreSQL state back into
RabbitMQ so persisted webhook deliveries are not lost when RabbitMQ was down at
HTTP-ingestion time.
"""

from __future__ import annotations

import argparse
import time

import structlog

from app.actors.maintenance import reconcile_inbox_documents
from app.config import settings
from app.jobs.recovery import enqueue_webhook_delivery, run_recovery_scan

log = structlog.get_logger(__name__)


def enqueue_poll_reconciliation(limit: int | None = None) -> None:
    """Enqueue polling reconciliation through the maintenance actor."""
    send = getattr(reconcile_inbox_documents, "send", None)
    if send is not None:
        send(limit)
        return

    reconcile_inbox_documents(limit)


def run_recovery_loop(
    *, interval_seconds: int | None = None, once: bool = False, limit: int = 100
) -> None:
    """Run durable recovery and periodic Paperless polling reconciliation."""
    recovery_interval = 30 if interval_seconds is None else interval_seconds
    poll_interval = max(1, settings.poll_interval_seconds)
    last_poll_at = -float(poll_interval)
    while True:
        now = time.monotonic()
        run_recovery_scan(limit=limit)
        if settings.poll_interval_seconds > 0 and now - last_poll_at >= poll_interval:
            enqueue_poll_reconciliation(limit=None)
            last_poll_at = now
        if once:
            return
        sleep_seconds = max(1, recovery_interval)
        log.info(
            "event worker recovery loop sleeping",
            interval_seconds=sleep_seconds,
            poll_interval_seconds=settings.poll_interval_seconds,
        )
        time.sleep(sleep_seconds)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Archibot event-driven worker utilities")
    subparsers = parser.add_subparsers(dest="command", required=True)

    recovery = subparsers.add_parser("recovery-scan", help="Requeue durable pending work")
    recovery.add_argument("--once", action="store_true", help="Run one scan and exit")
    recovery.add_argument(
        "--interval-seconds", type=int, default=None, help="Seconds between scans"
    )
    recovery.add_argument(
        "--limit", type=int, default=100, help="Maximum queued deliveries per scan"
    )

    enqueue_webhook = subparsers.add_parser(
        "enqueue-webhook", help="Enqueue one persisted webhook delivery"
    )
    enqueue_webhook.add_argument(
        "--delivery-id",
        type=int,
        required=True,
        help="Persisted webhook_deliveries id to enqueue",
    )

    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    if args.command == "recovery-scan":
        run_recovery_loop(interval_seconds=args.interval_seconds, once=args.once, limit=args.limit)
        return 0
    if args.command == "enqueue-webhook":
        enqueue_webhook_delivery(args.delivery_id)
        log.info("webhook delivery enqueue requested", webhook_delivery_id=args.delivery_id)
        return 0

    raise ValueError(f"Unsupported command: {args.command}")


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())
