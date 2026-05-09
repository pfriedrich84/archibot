# ADR-0005: Use Paperless Webhooks as the Primary Trigger

## Status

Accepted

## Context

Polling-only processing delays document handling and encourages large batch-style worker flows. Paperless can notify Archibot when documents are created or changed, which better fits an event-driven pipeline.

## Decision

Use Paperless webhooks as the primary trigger for document processing.

Webhook ingestion must validate, persist, deduplicate and enqueue quickly. It must not perform heavy Paperless, OCR, embedding, classification or LLM work in the HTTP request.

Periodic polling remains automatic every 600 seconds as reconciliation/fallback and must use the same pipeline-start, dedupe and locking logic as webhooks.

## Consequences

- The new target endpoint is `POST /api/webhooks/paperless`.
- Webhook deliveries are durable and auditable.
- Duplicate deliveries must not create duplicate pipeline runs.
- Polling catches missed webhooks and stale state but is no longer the primary processing path.
