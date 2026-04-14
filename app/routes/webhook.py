"""Optional webhook endpoints for Paperless workflow and post-consume hooks."""

from __future__ import annotations

import secrets

import structlog
from fastapi import APIRouter, Header, Request
from fastapi.responses import JSONResponse

from app.config import settings
from app.indexer import is_reindexing
from app.worker import _process_document

log = structlog.get_logger(__name__)
router = APIRouter(prefix="/webhook")


# ---------------------------------------------------------------------------
# Payload helpers
# ---------------------------------------------------------------------------
def _extract_document_id(body: dict) -> int | None:
    """Extract document_id from various Paperless webhook payload formats.

    Supported formats:
      - Workflow webhook: ``{"event": "...", "object": {"id": 123, ...}}``
      - Post-consume:    ``{"document_id": 123}``
    """
    # Paperless workflow webhook format
    obj = body.get("object")
    if isinstance(obj, dict):
        raw = obj.get("id")
        if raw is not None:
            try:
                return int(raw)
            except (ValueError, TypeError):
                pass

    # Legacy post-consume format
    raw = body.get("document_id")
    if raw is not None:
        try:
            return int(raw)
        except (ValueError, TypeError):
            pass

    return None


def _verify_webhook_secret(secret_header: str | None) -> JSONResponse | None:
    """Return a 403 response if the webhook secret is configured and doesn't match."""
    if settings.webhook_secret and (
        not secret_header or not secrets.compare_digest(secret_header, settings.webhook_secret)
    ):
        return JSONResponse(status_code=403, content={"detail": "Invalid webhook secret"})
    return None


# ---------------------------------------------------------------------------
# Full processing webhook
# ---------------------------------------------------------------------------
@router.post("/paperless")
async def paperless_webhook(
    request: Request,
    x_webhook_secret: str | None = Header(default=None),
):
    """Process a single document triggered by a Paperless webhook.

    Accepts both Paperless workflow webhook payloads
    (``{"event": "...", "object": {"id": ...}}``) and legacy post-consume
    payloads (``{"document_id": ...}``).
    """
    auth_error = _verify_webhook_secret(x_webhook_secret)
    if auth_error:
        log.warning("webhook auth failed")
        return auth_error

    body = await request.json()
    doc_id = _extract_document_id(body)
    if doc_id is None:
        log.warning("webhook payload missing document id", payload=body)
        return JSONResponse(
            status_code=422,
            content={"detail": "Could not extract document_id from payload"},
        )

    if is_reindexing():
        log.info("reindex in progress — rejecting webhook", document_id=doc_id)
        return JSONResponse(
            status_code=503,
            content={"detail": "Reindex in progress, try again later"},
        )

    paperless = request.app.state.paperless
    ollama = request.app.state.ollama

    log.info("webhook triggered", document_id=doc_id, webhook_event=body.get("event"))

    try:
        doc = await paperless.get_document(doc_id)
        correspondents = await paperless.list_correspondents()
        doctypes = await paperless.list_document_types()
        storage_paths = await paperless.list_storage_paths()
        tags = await paperless.list_tags()

        await _process_document(
            doc,
            paperless,
            ollama,
            correspondents,
            doctypes,
            storage_paths,
            tags,
        )
        return {"status": "ok", "document_id": doc_id}
    except Exception as exc:
        log.error("webhook processing failed", document_id=doc_id, error=str(exc))
        return {"status": "error", "document_id": doc_id, "error": str(exc)}
