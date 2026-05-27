"""Shared context DTOs and text helpers for classification context."""

from __future__ import annotations

from dataclasses import dataclass

from app.config import settings
from app.models import PaperlessDocument


@dataclass
class SimilarDocument:
    """A document paired with its similarity distance from a query vector."""

    document: PaperlessDocument
    distance: float


def document_summary(doc: PaperlessDocument) -> str:
    """Short, embedding-friendly text representation of a document."""
    parts = [doc.title or ""]
    if doc.content:
        parts.append(doc.content)
    text = "\n".join(part for part in parts if part)
    return text[: settings.embed_max_chars]
