"""Event-driven review commit helpers."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from app.clients.paperless import PaperlessClient
from app.jobs.database import engine
from app.pipeline.tag_policy import reserved_classification_tag_ids


@dataclass(frozen=True)
class ReviewCommitRecord:
    id: int
    paperless_document_id: int
    proposed_title: str | None
    proposed_date: str | None
    proposed_correspondent_id: int | None
    proposed_document_type_id: int | None
    proposed_storage_path_id: int | None
    proposed_tags: list[dict[str, Any]]
    paperless_version_id: int | None = None
    paperless_version_checksum: str | None = None


def sql_text(statement: str):
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError("sqlalchemy is required for PostgreSQL-backed review commits") from exc

    return text(statement)


def list_review_suggestions_ready_to_commit(limit: int = 100) -> list[int]:
    """Return accepted event-driven review suggestions that need commit."""
    statement = sql_text(
        """
        SELECT id
        FROM review_suggestions
        WHERE status = 'accepted'
          AND (commit_status IS NULL OR commit_status = 'queued')
        ORDER BY reviewed_at ASC NULLS LAST, updated_at ASC, id ASC
        LIMIT :limit
        """
    )
    with engine().connect() as connection:
        rows = connection.execute(statement, {"limit": limit}).mappings().all()

    return [int(row["id"]) for row in rows]


def load_review_commit(review_suggestion_id: int) -> ReviewCommitRecord | None:
    """Load fields needed to patch Paperless for one accepted suggestion."""
    statement = sql_text(
        """
        SELECT id,
               paperless_document_id,
               paperless_version_id,
               paperless_version_checksum,
               proposed_title,
               proposed_date,
               proposed_correspondent_id,
               proposed_document_type_id,
               proposed_storage_path_id,
               proposed_tags
        FROM review_suggestions
        WHERE id = :review_suggestion_id
          AND status = 'accepted'
        """
    )
    with engine().connect() as connection:
        row = (
            connection.execute(statement, {"review_suggestion_id": review_suggestion_id})
            .mappings()
            .first()
        )

    if row is None:
        return None

    proposed_tags = row["proposed_tags"] or []
    if not isinstance(proposed_tags, list):
        proposed_tags = []

    return ReviewCommitRecord(
        id=int(row["id"]),
        paperless_document_id=int(row["paperless_document_id"]),
        proposed_title=None if row["proposed_title"] is None else str(row["proposed_title"]),
        proposed_date=None if row["proposed_date"] is None else str(row["proposed_date"]),
        proposed_correspondent_id=_optional_int(row["proposed_correspondent_id"]),
        proposed_document_type_id=_optional_int(row["proposed_document_type_id"]),
        proposed_storage_path_id=_optional_int(row["proposed_storage_path_id"]),
        proposed_tags=proposed_tags,
        paperless_version_id=_optional_int(row["paperless_version_id"]),
        paperless_version_checksum=None
        if row["paperless_version_checksum"] is None
        else str(row["paperless_version_checksum"]),
    )


def _optional_int(value: object) -> int | None:
    return None if value is None else int(value)


def _assignable_tag_ids(
    proposed_tags: list[dict[str, Any]], forbidden_tag_ids: set[int] | None = None
) -> set[int]:
    """Resolve proposed IDs while reserving workflow-control tags."""
    reserved_ids = reserved_classification_tag_ids([]) | (forbidden_tag_ids or set())
    return {
        int(tag["id"])
        for tag in proposed_tags
        if isinstance(tag, dict)
        and tag.get("id") is not None
        and int(tag["id"]) not in reserved_ids
    }


def build_paperless_patch(
    record: ReviewCommitRecord,
    current_tags: list[int],
    current_storage_path: int | None,
    forbidden_tag_ids: set[int] | None = None,
) -> dict[str, Any]:
    """Build safe Paperless PATCH fields from reviewed IDs only."""
    fields: dict[str, Any] = {}
    if record.proposed_title:
        fields["title"] = record.proposed_title
    if record.proposed_date:
        fields["created"] = record.proposed_date
    if record.proposed_correspondent_id is not None:
        fields["correspondent"] = record.proposed_correspondent_id
    if record.proposed_document_type_id is not None:
        fields["document_type"] = record.proposed_document_type_id
    # A live existing Paperless storage path is authoritative. The only writable
    # case is an explicitly accepted review filling a currently absent value.
    if current_storage_path is None and record.proposed_storage_path_id is not None:
        fields["storage_path"] = record.proposed_storage_path_id

    tag_ids = _assignable_tag_ids(record.proposed_tags, forbidden_tag_ids)
    if tag_ids:
        fields["tags"] = sorted(set(current_tags) | tag_ids)

    return fields


def paperless_document_matches_review(
    record: ReviewCommitRecord,
    document: Any,
    forbidden_tag_ids: set[int] | None = None,
) -> bool:
    """Return whether Paperless already contains every reviewed writable value.

    This closes the activity retry gap after Paperless accepted a PATCH but the
    worker stopped before ArchiBot persisted the successful projection.
    """
    if record.proposed_title and document.title != record.proposed_title:
        return False
    if record.proposed_date and document.document_date != record.proposed_date:
        return False
    if (
        record.proposed_correspondent_id is not None
        and document.correspondent != record.proposed_correspondent_id
    ):
        return False
    if (
        record.proposed_document_type_id is not None
        and document.document_type != record.proposed_document_type_id
    ):
        return False
    if document.storage_path is None and record.proposed_storage_path_id is not None:
        return False

    proposed_tag_ids = _assignable_tag_ids(record.proposed_tags, forbidden_tag_ids)
    return proposed_tag_ids.issubset(set(document.tags))


async def commit_review_suggestion_to_paperless(
    record: ReviewCommitRecord, paperless: PaperlessClient
) -> dict[str, Any]:
    """Patch Paperless for one accepted review suggestion."""
    document = await paperless.get_document(record.paperless_document_id)
    tags = await paperless.list_tags()
    forbidden_tag_ids = reserved_classification_tag_ids(tags)
    version_changed = (
        record.paperless_version_id is not None
        and document.current_version_id != record.paperless_version_id
    )
    checksum_changed = (
        record.paperless_version_checksum is not None
        and document.current_version_checksum != record.paperless_version_checksum
    )
    if (version_changed or checksum_changed) and paperless_document_matches_review(
        record, document, forbidden_tag_ids
    ):
        return {}
    if version_changed:
        raise ValueError("Paperless document version changed before commit")
    if checksum_changed:
        raise ValueError("Paperless document checksum changed before commit")
    fields = build_paperless_patch(
        record, document.tags, document.storage_path, forbidden_tag_ids
    )
    if fields:
        await paperless.patch_reviewed_document(record.paperless_document_id, fields)
    return fields


def mark_review_commit_status(
    review_suggestion_id: int, status: str, error: str | None = None
) -> None:
    statement = sql_text(
        """
        UPDATE review_suggestions
        SET commit_status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :review_suggestion_id
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {"review_suggestion_id": review_suggestion_id, "status": status, "error": error},
        )
