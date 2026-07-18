"""Judge seam used by the durable document actor."""

from __future__ import annotations

import structlog

from app.config import settings
from app.models import ClassificationResult, JudgeVerdict, PaperlessDocument, PaperlessEntity
from app.pipeline import classifier
from app.pipeline.ports import AiProviderGateway
from app.pipeline.processing_models import JudgeOutcome

log = structlog.get_logger(__name__)


async def maybe_run_judge(
    doc: PaperlessDocument,
    initial: ClassificationResult,
    raw_response: str,
    context_docs: list[PaperlessDocument],
    correspondents: list[PaperlessEntity],
    doctypes: list[PaperlessEntity],
    storage_paths: list[PaperlessEntity],
    tags: list[PaperlessEntity],
    provider: AiProviderGateway,
) -> JudgeOutcome:
    """Run optional judge verification without legacy timing/state writes."""
    if getattr(settings, "enable_judge_verification", False) is not True:
        return JudgeOutcome(result=initial)
    threshold = getattr(settings, "judge_confidence_threshold", 101)
    if not isinstance(threshold, int | float):
        threshold = 101
    if initial.confidence >= threshold:
        return JudgeOutcome(result=initial, verdict="skipped")
    try:
        verdict: JudgeVerdict = await classifier.verify(
            doc, context_docs, initial, correspondents, doctypes, storage_paths, tags, provider
        )
    except Exception as exc:
        log.warning("judge verification raised", doc_id=doc.id, error_type=type(exc).__name__)
        return JudgeOutcome(result=initial, verdict="error", reasoning=str(exc)[:300])
    if verdict.verdict == "corrected" and verdict.corrected is not None:
        return JudgeOutcome(
            result=verdict.corrected,
            verdict="corrected",
            reasoning=verdict.reasoning or None,
            original_proposed_json=raw_response,
        )
    return JudgeOutcome(
        result=initial, verdict=verdict.verdict, reasoning=verdict.reasoning or None
    )
