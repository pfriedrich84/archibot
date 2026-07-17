"""Idempotency helper for persisted webhook deliveries."""

from __future__ import annotations


def webhook_dedupe_key(
    *,
    source: str,
    event_type: str,
    paperless_document_id: int,
    paperless_modified: str | None,
    payload_hash: str,
) -> str:
    return ":".join(
        [
            source,
            event_type,
            str(paperless_document_id),
            paperless_modified or "unknown_modified",
            payload_hash,
        ]
    )
