"""PostgreSQL helpers for webhook delivery actor state."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from app.jobs.database import engine


@dataclass(frozen=True)
class WebhookDeliveryRecord:
    id: int
    event_type: str
    webhook_action: str | None
    paperless_document_id: int
    paperless_modified: str | None
    status: str
    normalized_payload: dict[str, Any]


def load_webhook_delivery(webhook_delivery_id: int) -> WebhookDeliveryRecord | None:
    """Load the normalized state needed by the webhook Absurd actor."""
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError("sqlalchemy is required for PostgreSQL-backed webhook actors") from exc

    statement = text(
        """
        SELECT id, event_type, paperless_document_id, status, normalized_payload
        FROM webhook_deliveries
        WHERE id = :webhook_delivery_id
        """
    )
    with engine().connect() as connection:
        row = (
            connection.execute(statement, {"webhook_delivery_id": webhook_delivery_id})
            .mappings()
            .first()
        )

    if row is None:
        return None

    normalized_payload = row["normalized_payload"] or {}
    if not isinstance(normalized_payload, dict):
        normalized_payload = {}

    paperless_modified = normalized_payload.get("paperless_modified")
    webhook_action = normalized_payload.get("webhook_action")
    return WebhookDeliveryRecord(
        id=int(row["id"]),
        event_type=str(row["event_type"]),
        webhook_action=None if webhook_action is None else str(webhook_action),
        paperless_document_id=int(row["paperless_document_id"]),
        paperless_modified=None if paperless_modified is None else str(paperless_modified),
        status=str(row["status"]),
        normalized_payload=normalized_payload,
    )


def list_queued_webhook_delivery_ids(limit: int = 100) -> list[int]:
    """Return queued webhook deliveries eligible for actor enqueue/recovery."""
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError("sqlalchemy is required for PostgreSQL-backed webhook actors") from exc

    statement = text(
        """
        SELECT id
        FROM webhook_deliveries
        WHERE status = 'queued'
        ORDER BY received_at ASC, id ASC
        LIMIT :limit
        """
    )
    with engine().connect() as connection:
        rows = connection.execute(statement, {"limit": limit}).mappings().all()

    return [int(row["id"]) for row in rows]


def list_embedding_blocked_webhook_delivery_ids(limit: int = 100) -> list[int]:
    """Return webhook deliveries blocked only by the embedding readiness gate."""
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError("sqlalchemy is required for PostgreSQL-backed webhook actors") from exc

    statement = text(
        """
        SELECT id
        FROM webhook_deliveries
        WHERE status = 'blocked'
          AND error = 'embedding_index_not_ready'
        ORDER BY received_at ASC, id ASC
        LIMIT :limit
        """
    )
    with engine().connect() as connection:
        rows = connection.execute(statement, {"limit": limit}).mappings().all()

    return [int(row["id"]) for row in rows]


def mark_webhook_delivery_status(
    webhook_delivery_id: int, status: str, error: str | None = None
) -> None:
    """Persist webhook actor outcome without storing document contents in logs."""
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError("sqlalchemy is required for PostgreSQL-backed webhook actors") from exc

    statement = text(
        """
        UPDATE webhook_deliveries
        SET status = CAST(:status AS character varying),
            error = :error,
            processed_at = CASE
                WHEN CAST(:status_for_lifecycle AS character varying) IN ('processed', 'blocked', 'failed', 'failed_permanent') THEN CURRENT_TIMESTAMP
                WHEN CAST(:status_for_lifecycle AS character varying) = 'queued' THEN NULL
                ELSE processed_at
            END,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :webhook_delivery_id
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {
                "webhook_delivery_id": webhook_delivery_id,
                "status": status,
                "status_for_lifecycle": status,
                "error": error,
            },
        )
