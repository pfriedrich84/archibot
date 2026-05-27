"""PostgreSQL/pgvector document embedding persistence."""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass
from datetime import datetime
from typing import Any

from app.config import settings
from app.jobs.database import engine


@dataclass(frozen=True)
class DocumentEmbeddingInput:
    paperless_document_id: int
    title: str
    content: str
    embedding_model: str
    embedding: list[float]
    created_date: str | None = None
    metadata: dict[str, Any] | None = None


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
    parts = [title or "", content or ""]
    return "\n".join(part for part in parts if part).strip()[: settings.embed_max_chars]


def content_hash_for_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def pgvector_literal(embedding: list[float]) -> str:
    """Return a pgvector-compatible vector literal without logging values."""
    return "[" + ",".join(str(float(value)) for value in embedding) + "]"


def store_document_embedding(item: DocumentEmbeddingInput) -> str | None:
    """Persist a document embedding in PostgreSQL/pgvector.

    Returns the content hash, or `None` when there is no text to embed.
    """
    text = document_embedding_text(item.title, item.content)
    if not text:
        return None

    content_hash = content_hash_for_text(text)
    dimensions = len(item.embedding)
    statement = sql_text(
        """
        INSERT INTO document_embeddings (
            paperless_document_id,
            content_hash,
            embedding_model,
            dimensions,
            embedding,
            created_at,
            updated_at
        ) VALUES (
            :paperless_document_id,
            :content_hash,
            :embedding_model,
            :dimensions,
            CAST(:embedding AS vector),
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
        ON CONFLICT ON CONSTRAINT document_embeddings_dedupe_unique
        DO UPDATE SET
            embedding = EXCLUDED.embedding,
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
                "created_date": item.created_date,
                "metadata": json.dumps(item.metadata or {}, default=_json_default),
            },
        )

    return content_hash


def _json_default(value: object) -> str:
    if isinstance(value, datetime):
        return value.isoformat()
    return str(value)
