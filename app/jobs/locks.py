"""Lock key helpers for event-driven pipeline coordination."""

from __future__ import annotations


def document_lock_key(paperless_document_id: int) -> str:
    return f"archibot:document:{paperless_document_id}"


def webhook_document_lock_key(paperless_document_id: int) -> str:
    return f"archibot:webhook-document:{paperless_document_id}"


REINDEX_LOCK_KEY = "archibot:reindex"
