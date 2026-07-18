"""Durable discovery handoff from Python polling to Laravel Pipeline Start."""

from __future__ import annotations

import hashlib
import json
import uuid
from dataclasses import dataclass
from typing import Any

from sqlalchemy import text

from app.jobs.database import engine

PROTOCOL_VERSION = 1
_NAMESPACE = uuid.UUID("40ed7478-d9cc-4f21-943f-359425d6e969")


@dataclass(frozen=True)
class PollCandidateResult:
    candidate_id: str
    created: bool


def persist_poll_candidate(
    *,
    command_id: int,
    paperless_document_id: int,
    discovered_modified: str | None,
    marker_disposition: str,
    force: bool,
) -> PollCandidateResult:
    """Persist one idempotent protocol-v1 candidate; never create a Pipeline Run."""
    trigger_metadata: dict[str, Any] = {
        "trigger_source": "poll",
        "force": force,
        "command_id": command_id,
    }
    identity = json.dumps(
        {
            "protocol_version": PROTOCOL_VERSION,
            "command_id": command_id,
            "paperless_document_id": paperless_document_id,
            "discovered_modified": discovered_modified,
            "marker_disposition": marker_disposition,
            "force": force,
        },
        sort_keys=True,
        separators=(",", ":"),
    )
    idempotency_key = hashlib.sha256(identity.encode("utf-8")).hexdigest()
    candidate_id = str(uuid.uuid5(_NAMESPACE, idempotency_key))

    with engine().begin() as connection:
        result = connection.execute(
            text(
                """
                INSERT INTO poll_candidates (
                    candidate_id, protocol_version, command_id,
                    paperless_document_id, discovered_modified,
                    marker_disposition, trigger_metadata, idempotency_key,
                    status, claim_attempts, created_at, updated_at
                ) VALUES (
                    :candidate_id, :protocol_version, :command_id,
                    :paperless_document_id, :discovered_modified,
                    :marker_disposition, CAST(:trigger_metadata AS JSON), :idempotency_key,
                    'ready', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                ON CONFLICT (idempotency_key) DO NOTHING
                """
            ),
            {
                "candidate_id": candidate_id,
                "protocol_version": PROTOCOL_VERSION,
                "command_id": command_id,
                "paperless_document_id": paperless_document_id,
                "discovered_modified": discovered_modified,
                "marker_disposition": marker_disposition,
                "trigger_metadata": json.dumps(trigger_metadata, sort_keys=True),
                "idempotency_key": idempotency_key,
            },
        )

    return PollCandidateResult(candidate_id=candidate_id, created=result.rowcount == 1)
