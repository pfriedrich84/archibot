"""Trusted Document rules for classification context."""

from __future__ import annotations

from typing import Any

from app.config import settings
from app.models import PaperlessDocument


def trusted_context_scope() -> str:
    """Return the durable scope name for Trusted Document context."""
    return "without_inbox_tag"


def is_trusted_document(document: PaperlessDocument | Any) -> bool:
    """Return whether a Paperless Document is trusted classification context.

    Domain rule: a Trusted Document is a Paperless Document without the
    configured inbox tag. If no inbox tag is configured, there is no tag to
    exclude and all Paperless Documents are trusted for context.
    """
    inbox_tag_id = settings.paperless_inbox_tag_id
    if inbox_tag_id is None:
        return True
    tags = getattr(document, "tags", None) or []
    return int(inbox_tag_id) not in {int(tag) for tag in tags if tag is not None}
