"""AI classification tools — rate-limited, inbox-only."""

from __future__ import annotations

import json

import structlog
from mcp.server.fastmcp import Context, FastMCP
from mcp.types import ToolAnnotations

from app.config import settings
from app.mcp_tools._auth import check_api_key
from app.mcp_tools._deps import get_deps, get_paperless

log = structlog.get_logger(__name__)


def register(mcp: FastMCP) -> None:
    @mcp.tool(
        name="classify_document",
        description=(
            "Run the AI classification pipeline on an inbox document. "
            "Returns a classification suggestion with proposed title, date, "
            "correspondent, document type, tags, and confidence score. "
            "Only works on documents that carry the inbox tag. Rate-limited."
        ),
        annotations=ToolAnnotations(readOnlyHint=False, destructiveHint=False),
    )
    async def classify_document(document_id: int, ctx: Context = None) -> str:
        check_api_key(ctx)
        deps = get_deps(ctx)
        paperless = get_paperless(ctx)

        # Rate limit
        deps.rate_limiter.check("classify")

        # Fetch document using the verified user's Paperless token when Laravel MCP auth is enabled.
        doc = await paperless.get_document(document_id)

        # Inbox gate: only classify documents with the inbox tag
        if settings.paperless_inbox_tag_id not in doc.tags:
            return json.dumps(
                {
                    "error": (
                        f"Document {document_id} does not carry the inbox tag "
                        f"(tag ID {settings.paperless_inbox_tag_id}). "
                        "Only inbox documents can be classified via MCP."
                    )
                }
            )

        # Lazy imports to avoid circular dependencies at module load
        from app.pipeline import classifier, context_builder
        from app.pipeline.document_processing import store_suggestion
        from app.pipeline.ocr_correction import maybe_correct_ocr

        log.info("MCP classify_document", doc_id=document_id)

        # Optional OCR correction
        text, num_corrections = await maybe_correct_ocr(doc, deps.ollama, paperless)
        if num_corrections > 0:
            doc = doc.model_copy(update={"content": text})

        # Find similar documents for context
        context_docs = await context_builder.find_similar_documents(doc, paperless, deps.ollama)

        # Fetch entity lists
        correspondents = await paperless.list_correspondents()
        doctypes = await paperless.list_document_types()
        storage_paths = await paperless.list_storage_paths()
        tags = await paperless.list_tags()

        # Run classification
        result, raw_response = await classifier.classify(
            doc, context_docs, correspondents, doctypes, storage_paths, tags, deps.ollama
        )

        # Store suggestion in DB
        suggestion = store_suggestion(
            doc, result, raw_response, correspondents, doctypes, storage_paths, tags
        )

        # Index for future context
        await context_builder.index_document(doc, deps.ollama)

        log.info(
            "MCP classification complete",
            doc_id=document_id,
            suggestion_id=suggestion.id,
            confidence=result.confidence,
        )

        return json.dumps(
            {
                "suggestion_id": suggestion.id,
                "document_id": document_id,
                "proposed_title": result.title,
                "proposed_date": result.date,
                "proposed_correspondent": result.correspondent,
                "proposed_document_type": result.document_type,
                "proposed_storage_path": result.storage_path,
                "proposed_tags": [
                    {"name": t.name, "confidence": t.confidence} for t in result.tags
                ],
                "confidence": result.confidence,
                "reasoning": result.reasoning,
            },
            ensure_ascii=False,
            default=str,
        )
