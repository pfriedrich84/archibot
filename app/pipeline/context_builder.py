"""PostgreSQL/pgvector-backed compatibility facade for document context search."""

from __future__ import annotations

import structlog

from app.clients.ollama import OllamaClient
from app.clients.paperless import PaperlessClient
from app.config import settings
from app.jobs.document_embeddings import (
    DocumentEmbeddingInput,
    find_similar_document_ids,
    is_trusted_document,
    store_document_embedding,
)
from app.jobs.document_embeddings import (
    find_similar_by_id as _find_similar_by_id,
)
from app.jobs.document_embeddings import (
    find_similar_with_precomputed_embedding as _find_similar_with_precomputed_embedding,
)
from app.models import PaperlessDocument
from app.pipeline.context_types import SimilarDocument, document_summary

log = structlog.get_logger(__name__)


def store_embedding(doc: PaperlessDocument, embedding: list[float]) -> None:
    """Persist a pre-computed embedding in PostgreSQL/pgvector.

    This function keeps the legacy import Interface, but the implementation no
    longer writes sqlite-vec or FTS tables.
    """
    if not is_trusted_document(doc):
        return
    store_document_embedding(
        DocumentEmbeddingInput(
            paperless_document_id=doc.id,
            title=doc.title,
            content=doc.content,
            embedding_model=settings.ollama_embed_model,
            embedding=embedding,
            created_date=doc.created_date,
            correspondent_id=doc.correspondent,
            document_type_id=doc.document_type,
            storage_path_id=doc.storage_path,
            tags=doc.tags,
            paperless_modified=str(doc.modified) if doc.modified is not None else None,
            trusted_for_context=True,
        )
    )


async def index_document(doc: PaperlessDocument, ollama: OllamaClient) -> None:
    """Compute and persist an embedding for a single trusted document."""
    text = document_summary(doc)
    if not text.strip():
        return
    try:
        vec = await ollama.embed(text)
    except Exception as exc:
        log.warning("embedding failed", doc_id=doc.id, error=str(exc))
        return

    store_embedding(doc, vec)


async def find_similar_with_precomputed_embedding(
    doc: PaperlessDocument,
    embedding: list[float],
    paperless: PaperlessClient,
    limit: int | None = None,
) -> list[SimilarDocument]:
    """Vector search using a pre-computed embedding vector."""
    return await _find_similar_with_precomputed_embedding(doc, embedding, paperless, limit)


async def find_similar_with_distances(
    doc: PaperlessDocument,
    paperless: PaperlessClient,
    ollama: OllamaClient,
    limit: int | None = None,
) -> list[SimilarDocument]:
    """Return up to ``limit`` similar trusted documents with distances."""
    text = document_summary(doc)
    if not text.strip():
        return []

    try:
        vec = await ollama.embed(text)
    except Exception as exc:
        log.warning("context embedding failed", doc_id=doc.id, error=str(exc))
        return []

    return await find_similar_with_precomputed_embedding(doc, vec, paperless, limit)


async def find_similar_documents(
    doc: PaperlessDocument,
    paperless: PaperlessClient,
    ollama: OllamaClient,
    limit: int | None = None,
) -> list[PaperlessDocument]:
    """Return similar trusted documents without distance metadata."""
    results = await find_similar_with_distances(doc, paperless, ollama, limit)
    return [result.document for result in results]


async def _load_similar(
    hits: list[tuple[int, float]],
    paperless: PaperlessClient,
) -> list[SimilarDocument]:
    similar: list[SimilarDocument] = []
    for doc_id, distance in hits:
        try:
            similar.append(
                SimilarDocument(document=await paperless.get_document(doc_id), distance=distance)
            )
        except Exception as exc:
            log.warning("failed to load similar doc", id=doc_id, error=str(exc))
    return similar


async def find_similar_by_query_text_filtered(
    query_text: str,
    paperless: PaperlessClient,
    ollama: OllamaClient,
    limit: int | None = None,
    *,
    exclude_id: int | None = None,
    correspondent_id: int | None = None,
    doctype_id: int | None = None,
    date_from: str | None = None,
    date_to: str | None = None,
) -> list[SimilarDocument]:
    """Vector-only pgvector query search with optional metadata filters."""
    if not query_text.strip():
        return []
    limit = limit or settings.context_max_docs

    try:
        vec = await ollama.embed(query_text[: settings.embed_max_chars])
    except Exception as exc:
        log.warning("filtered query embedding failed", error=str(exc))
        return []

    hits = find_similar_document_ids(
        vec,
        exclude_id=exclude_id,
        limit=limit,
        max_distance=settings.context_max_distance,
        correspondent_id=correspondent_id,
        doctype_id=doctype_id,
        date_from=date_from,
        date_to=date_to,
    )
    return await _load_similar(hits, paperless)


async def find_similar_by_query_text(
    query_text: str,
    paperless: PaperlessClient,
    ollama: OllamaClient,
    limit: int | None = None,
) -> list[SimilarDocument]:
    """Embed raw query text and find similar trusted documents."""
    return await find_similar_by_query_text_filtered(query_text, paperless, ollama, limit)


def find_similar_by_id(document_id: int, limit: int = 10) -> list[tuple[int, float]]:
    """Vector search using a document's stored pgvector embedding."""
    return _find_similar_by_id(document_id, limit)
