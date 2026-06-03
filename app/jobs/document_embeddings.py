"""PostgreSQL/pgvector document embedding persistence and trusted context search."""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass
from datetime import datetime
from typing import Any

import structlog

from app.config import settings
from app.jobs.database import engine
from app.models import PaperlessDocument
from app.pipeline.context_types import SimilarDocument, document_summary
from app.pipeline.trusted_context import is_trusted_document

log = structlog.get_logger(__name__)


@dataclass(frozen=True)
class DocumentEmbeddingInput:
    paperless_document_id: int
    title: str
    content: str
    embedding_model: str
    embedding: list[float]
    created_date: str | None = None
    metadata: dict[str, Any] | None = None
    correspondent_id: int | None = None
    document_type_id: int | None = None
    storage_path_id: int | None = None
    tags: list[int] | None = None
    paperless_modified: str | None = None
    trusted_for_context: bool = False


@dataclass(frozen=True)
class DocumentEmbeddingRow:
    paperless_document_id: int
    title: str | None
    correspondent_id: int | None
    document_type_id: int | None
    storage_path_id: int | None
    tags: list[int]
    created_date: str | None
    trusted_for_context: bool
    updated_at: str | None


def sql_text(statement: str):
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError(
            "sqlalchemy is required for PostgreSQL-backed document embeddings"
        ) from exc

    return text(statement)


def document_embedding_text(title: str, content: str) -> str:
    """Return bounded text used for pgvector embeddings."""
    return document_summary(PaperlessDocument(id=0, title=title or "", content=content or ""))


def content_hash_for_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def pgvector_literal(embedding: list[float]) -> str:
    """Return a pgvector-compatible vector literal without logging values."""
    return "[" + ",".join(str(float(value)) for value in embedding) + "]"


def _metadata_value(item: DocumentEmbeddingInput, key: str) -> Any:
    return (item.metadata or {}).get(key)


def _modified_value(item: DocumentEmbeddingInput) -> str | None:
    value = (
        item.paperless_modified
        if item.paperless_modified is not None
        else _metadata_value(item, "modified")
    )
    if isinstance(value, datetime):
        return value.isoformat()
    return None if value is None else str(value)


def _tags_value(item: DocumentEmbeddingInput) -> list[int]:
    raw = item.tags if item.tags is not None else _metadata_value(item, "tags")
    if not isinstance(raw, list):
        return []
    values: list[int] = []
    for value in raw:
        try:
            values.append(int(value))
        except (TypeError, ValueError):
            continue
    return values


def store_document_embedding(item: DocumentEmbeddingInput) -> str | None:
    """Persist a document embedding in PostgreSQL/pgvector.

    Returns the content hash, or `None` when there is no text to embed.
    """
    text = document_embedding_text(item.title, item.content)
    if not text:
        return None

    content_hash = content_hash_for_text(text)
    dimensions = len(item.embedding)
    tags = _tags_value(item)
    statement = sql_text(
        """
        INSERT INTO document_embeddings (
            paperless_document_id,
            content_hash,
            embedding_model,
            dimensions,
            embedding,
            title,
            created_date,
            correspondent_id,
            document_type_id,
            storage_path_id,
            tags_json,
            trusted_for_context,
            paperless_modified,
            created_at,
            updated_at
        ) VALUES (
            :paperless_document_id,
            :content_hash,
            :embedding_model,
            :dimensions,
            CAST(:embedding AS vector),
            :title,
            :created_date,
            :correspondent_id,
            :document_type_id,
            :storage_path_id,
            :tags_json,
            :trusted_for_context,
            :paperless_modified,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
        ON CONFLICT ON CONSTRAINT document_embeddings_dedupe_unique
        DO UPDATE SET
            embedding = EXCLUDED.embedding,
            title = EXCLUDED.title,
            created_date = EXCLUDED.created_date,
            correspondent_id = EXCLUDED.correspondent_id,
            document_type_id = EXCLUDED.document_type_id,
            storage_path_id = EXCLUDED.storage_path_id,
            tags_json = EXCLUDED.tags_json,
            trusted_for_context = EXCLUDED.trusted_for_context,
            paperless_modified = EXCLUDED.paperless_modified,
            updated_at = CURRENT_TIMESTAMP
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {
                "paperless_document_id": item.paperless_document_id,
                "content_hash": content_hash,
                "embedding_model": item.embedding_model,
                "dimensions": dimensions,
                "embedding": pgvector_literal(item.embedding),
                "title": item.title,
                "created_date": item.created_date,
                "correspondent_id": item.correspondent_id
                if item.correspondent_id is not None
                else _metadata_value(item, "correspondent"),
                "document_type_id": item.document_type_id
                if item.document_type_id is not None
                else _metadata_value(item, "document_type"),
                "storage_path_id": item.storage_path_id
                if item.storage_path_id is not None
                else _metadata_value(item, "storage_path"),
                "tags_json": json.dumps(tags, default=_json_default),
                "trusted_for_context": item.trusted_for_context,
                "paperless_modified": _modified_value(item),
            },
        )

    return content_hash


def delete_document_embeddings_for_document(paperless_document_id: int) -> int:
    """Delete all stored embeddings for one Paperless document."""
    statement = sql_text(
        """
        DELETE FROM document_embeddings
        WHERE paperless_document_id = :paperless_document_id
        """
    )
    with engine().begin() as connection:
        result = connection.execute(
            statement,
            {"paperless_document_id": paperless_document_id},
        )

    return int(result.rowcount or 0)


def delete_stale_document_embeddings_for_document(
    *,
    paperless_document_id: int,
    keep_content_hash: str,
    embedding_model: str,
    dimensions: int,
) -> int:
    """Delete old embeddings for one document after a newer content hash is stored."""
    statement = sql_text(
        """
        DELETE FROM document_embeddings
        WHERE paperless_document_id = :paperless_document_id
          AND embedding_model = :embedding_model
          AND dimensions = :dimensions
          AND content_hash != :keep_content_hash
        """
    )
    with engine().begin() as connection:
        result = connection.execute(
            statement,
            {
                "paperless_document_id": paperless_document_id,
                "keep_content_hash": keep_content_hash,
                "embedding_model": embedding_model,
                "dimensions": dimensions,
            },
        )

    return int(result.rowcount or 0)


def find_similar_document_ids(
    embedding: list[float],
    *,
    exclude_id: int | None = None,
    limit: int = 10,
    max_distance: float = 0.0,
    embedding_model: str | None = None,
    dimensions: int | None = None,
    correspondent_id: int | None = None,
    doctype_id: int | None = None,
    date_from: str | None = None,
    date_to: str | None = None,
) -> list[tuple[int, float]]:
    """Return trusted pgvector nearest-neighbour document ids and distances."""
    filters = ["recency_rank = 1", "trusted_for_context = TRUE"]
    params: dict[str, Any] = {
        "embedding": pgvector_literal(embedding),
        "limit": limit,
        "max_distance": max_distance,
        "embedding_model": embedding_model or settings.ollama_embed_model,
        "dimensions": dimensions or len(embedding),
        "exclude_id": exclude_id,
        "correspondent_id": correspondent_id,
        "doctype_id": doctype_id,
        "date_from": date_from,
        "date_to": date_to,
    }
    if exclude_id is not None:
        filters.append("paperless_document_id != :exclude_id")
    if correspondent_id is not None:
        filters.append("correspondent_id = :correspondent_id")
    if doctype_id is not None:
        filters.append("document_type_id = :doctype_id")
    if date_from:
        filters.append("created_date >= :date_from")
    if date_to:
        filters.append("created_date <= :date_to")

    distance_expr = "embedding <-> CAST(:embedding AS vector)"
    having = "WHERE distance <= :max_distance" if max_distance > 0 else ""
    statement = sql_text(
        f"""
        WITH current_embeddings AS (
            SELECT *,
                   ROW_NUMBER() OVER (
                       PARTITION BY paperless_document_id
                       ORDER BY updated_at DESC, created_at DESC
                   ) AS recency_rank
            FROM document_embeddings
            WHERE embedding_model = :embedding_model
              AND dimensions = :dimensions
        )
        SELECT paperless_document_id, distance
        FROM (
            SELECT paperless_document_id, {distance_expr} AS distance
            FROM current_embeddings
            WHERE {" AND ".join(filters)}
            ORDER BY {distance_expr} ASC
            LIMIT :limit
        ) nearest
        {having}
        ORDER BY distance ASC
        """
    )
    with engine().begin() as connection:
        rows = connection.execute(statement, params).mappings().all()
    return [(int(row["paperless_document_id"]), float(row["distance"])) for row in rows]


async def find_similar_with_precomputed_embedding(
    doc: PaperlessDocument,
    embedding: list[float],
    paperless,
    limit: int | None = None,
) -> list[SimilarDocument]:
    """Load trusted context documents from Paperless using pgvector search."""
    hits = find_similar_document_ids(
        embedding,
        exclude_id=doc.id,
        limit=limit or settings.context_max_docs,
        max_distance=settings.context_max_distance,
    )
    similar: list[SimilarDocument] = []
    for doc_id, distance in hits:
        try:
            candidate = await paperless.get_document(doc_id)
        except Exception as exc:
            log.warning("failed to load similar doc", id=doc_id, error=str(exc))
            continue
        if not is_trusted_document(candidate):
            continue
        similar.append(SimilarDocument(document=candidate, distance=distance))
    return similar


def load_document_embedding_vector(document_id: int) -> list[float] | None:
    """Load a stored pgvector as a Python list when the driver returns one.

    This is primarily a compatibility helper for callers that search by id. If
    the installed driver returns pgvector as a string, parse the simple literal.
    """
    statement = sql_text(
        """
        SELECT embedding
        FROM document_embeddings
        WHERE paperless_document_id = :document_id
          AND trusted_for_context = TRUE
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
        """
    )
    with engine().begin() as connection:
        row = connection.execute(statement, {"document_id": document_id}).mappings().first()
    if row is None:
        return None
    value = row["embedding"]
    if isinstance(value, list):
        return [float(item) for item in value]
    if isinstance(value, str):
        stripped = value.strip("[]")
        if not stripped:
            return []
        return [float(item.strip()) for item in stripped.split(",")]
    return None


def find_similar_by_id(document_id: int, limit: int = 10) -> list[tuple[int, float]]:
    vector = load_document_embedding_vector(document_id)
    if vector is None:
        return []
    return find_similar_document_ids(vector, exclude_id=document_id, limit=limit)


def list_document_embedding_rows(limit: int = 100) -> tuple[int, list[DocumentEmbeddingRow]]:
    """Return dashboard-friendly embedding metadata from PostgreSQL."""
    total_statement = sql_text("SELECT COUNT(*) AS c FROM document_embeddings")
    rows_statement = sql_text(
        """
        SELECT paperless_document_id, title, correspondent_id, document_type_id,
               storage_path_id, tags_json, created_date, trusted_for_context, updated_at
        FROM document_embeddings
        ORDER BY updated_at DESC, paperless_document_id DESC
        LIMIT :limit
        """
    )
    with engine().begin() as connection:
        total = connection.execute(total_statement).mappings().first()
        rows = connection.execute(rows_statement, {"limit": limit}).mappings().all()
    items: list[DocumentEmbeddingRow] = []
    for row in rows:
        try:
            tags = json.loads(row["tags_json"] or "[]")
        except (TypeError, json.JSONDecodeError):
            tags = []
        items.append(
            DocumentEmbeddingRow(
                paperless_document_id=int(row["paperless_document_id"]),
                title=row["title"],
                correspondent_id=row["correspondent_id"],
                document_type_id=row["document_type_id"],
                storage_path_id=row["storage_path_id"],
                tags=tags if isinstance(tags, list) else [],
                created_date=row["created_date"],
                trusted_for_context=bool(row["trusted_for_context"]),
                updated_at=str(row["updated_at"]) if row["updated_at"] is not None else None,
            )
        )
    return (int(total["c"] if total else 0), items)


def count_document_embeddings(*, trusted_only: bool = False) -> int:
    statement = sql_text(
        """
        SELECT COUNT(*) AS c
        FROM document_embeddings
        WHERE (:trusted_only = FALSE OR trusted_for_context = TRUE)
        """
    )
    with engine().begin() as connection:
        row = connection.execute(statement, {"trusted_only": trusted_only}).mappings().first()
    return int(row["c"] if row else 0)


def _json_default(value: object) -> str:
    if isinstance(value, datetime):
        return value.isoformat()
    return str(value)
