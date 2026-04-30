"""Small data models for Dokument-Verarbeitung.

Keeping these result/intermediate types outside the orchestration module makes
`document_processing.py` easier to scan without splitting the processing flow
itself across many shallow modules.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Literal

from app.models import ClassificationResult
from app.pipeline.context_builder import SimilarDocument

ProcessResult = Literal["skipped", "classified", "auto_committed"]


@dataclass
class JudgeOutcome:
    """State produced by the optional Judge pass, ready for Vorschlag storage."""

    result: ClassificationResult
    verdict: str | None = None
    reasoning: str | None = None
    original_proposed_json: str | None = None


@dataclass
class EmbeddingResult:
    """Intermediate result from the embedding phase for a single Dokument."""

    embedding: list[float] | None = None
    similar_results: list[SimilarDocument] = field(default_factory=list)


@dataclass
class BatchProcessResult:
    """Summary of one batched Dokument-Verarbeitung run."""

    total: int
    skipped: int = 0
    classified: int = 0
    auto_committed: int = 0
    errored: int = 0
    cycle_id: str | None = None
