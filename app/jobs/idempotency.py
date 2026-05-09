"""Idempotency helpers for webhook and document pipeline starts."""

from __future__ import annotations

import hashlib


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


def pipeline_dedupe_key(
    *,
    paperless_document_id: int,
    paperless_modified: str | None,
    content_hash: str | None = None,
    pipeline_version: str = "v1",
) -> str:
    raw = ":".join(
        [
            str(paperless_document_id),
            paperless_modified or "unknown_modified",
            content_hash or "unknown_content",
            pipeline_version,
        ]
    )
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()
