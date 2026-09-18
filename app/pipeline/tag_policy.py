"""Classification tag safety policy derived from Paperless tag metadata."""

from __future__ import annotations

from app.config import settings
from app.models import PaperlessEntity
from app.pipeline.ocr_correction import ocr_requested_tag_id


def reserved_classification_tag_ids(tags: list[PaperlessEntity]) -> set[int]:
    """Return tags that ArchiBot must never propose or newly assign.

    The configured Inbox tag is a workflow boundary. Paperless descendants of
    that tag inherit the boundary recursively. The OCR request tag remains a
    separate reserved control tag.
    """
    reserved = {tag_id for tag_id in (ocr_requested_tag_id(),) if tag_id > 0}

    raw_inbox_id = getattr(settings, "paperless_inbox_tag_id", 0)
    try:
        inbox_id = int(raw_inbox_id or 0)
    except (TypeError, ValueError):
        inbox_id = 0
    if inbox_id <= 0:
        return reserved

    reserved.add(inbox_id)
    changed = True
    while changed:
        changed = False
        for tag in tags:
            if tag.id not in reserved and tag.parent in reserved:
                reserved.add(tag.id)
                changed = True
    return reserved


def reserved_classification_tag_names(tags: list[PaperlessEntity]) -> set[str]:
    """Return normalized names for every reserved classification tag."""
    reserved_ids = reserved_classification_tag_ids(tags)
    return {
        tag.name.strip().casefold() for tag in tags if tag.id in reserved_ids and tag.name.strip()
    }
