"""Stable Temporal names and workflow identity helpers."""

from __future__ import annotations

import re

RUNTIME_PROBE_WORKFLOW = "archibot.runtime_probe"
EMBEDDING_INDEX_WORKFLOW = "archibot.embedding_index"
POLL_RECONCILIATION_WORKFLOW = "archibot.poll_reconciliation"
DOCUMENT_WORKFLOW = "archibot.document"
REVIEW_COMMIT_WORKFLOW = "archibot.review_commit"
MODEL_PHASE_SCHEDULER_WORKFLOW = "archibot.model_phase_scheduler"
MODEL_PHASE_SCHEDULER_WORKFLOW_ID = "archibot/model-phase-scheduler"
WORKFLOW_PROTOCOL_VERSION = 1

EMBEDDING_TASK_QUEUE = "archibot-model-embedding"
OCR_TEXT_TASK_QUEUE = "archibot-model-ocr-text"
OCR_VISION_TASK_QUEUE = "archibot-model-ocr-vision"
CLASSIFICATION_TASK_QUEUE = "archibot-model-classification"
JUDGE_TASK_QUEUE = "archibot-model-judge"
PAPERLESS_TASK_QUEUE = "archibot-paperless"

_SAFE_IDENTIFIER = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$")


def runtime_probe_workflow_id(request_id: str) -> str:
    """Return a bounded workflow ID for an operator connectivity probe."""
    normalized = request_id.strip()
    if not _SAFE_IDENTIFIER.fullmatch(normalized):
        raise ValueError("request_id must be 1-128 safe identifier characters")
    return f"archibot/runtime-probe/{normalized}"
