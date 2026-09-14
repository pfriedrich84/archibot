"""Stable Temporal names and workflow identity helpers."""

from __future__ import annotations

import re

RUNTIME_PROBE_WORKFLOW = "archibot.runtime_probe"
EMBEDDING_INDEX_WORKFLOW = "archibot.embedding_index"
POLL_RECONCILIATION_WORKFLOW = "archibot.poll_reconciliation"
DOCUMENT_WORKFLOW = "archibot.document"
WORKFLOW_PROTOCOL_VERSION = 1

_SAFE_IDENTIFIER = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$")


def runtime_probe_workflow_id(request_id: str) -> str:
    """Return a bounded workflow ID for an operator connectivity probe."""
    normalized = request_id.strip()
    if not _SAFE_IDENTIFIER.fullmatch(normalized):
        raise ValueError("request_id must be 1-128 safe identifier characters")
    return f"archibot/runtime-probe/{normalized}"
