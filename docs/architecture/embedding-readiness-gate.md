# Embedding Readiness Gate

## Purpose

Document processing must not start until the global embedding index is complete.

Archibot uses embeddings as context for classification, similar-document lookup, review suggestions and later RAG-style reasoning. If documents are processed before the initial embedding index is complete, early classifications can be made with incomplete context.

## Hard Invariant

```text
No document processing before embedding_index_state.status = complete
```

This applies to all document-processing entrypoints:

- Paperless webhook-triggered processing
- periodic polling / reconciliation
- manual document processing from Laravel UI
- retry processing
- reindex-driven downstream processing after embedding rebuild

Webhook ingestion itself remains allowed. Webhook deliveries must be accepted, persisted and deduplicated, but processing stays pending/blocked until the embedding gate opens.

## Allowed Before Embedding Completion

Allowed:

- receive Paperless webhook deliveries
- validate webhook payloads
- persist raw and normalized webhook payloads
- deduplicate webhook deliveries
- create pending commands or pending pipeline runs
- show readiness state in Laravel UI
- start, resume or monitor the initial embedding build

Blocked:

- `start_document_pipeline`
- `fetch_document` for processing purposes
- `correct_ocr`
- webhook-triggered per-document embedding
- polling-triggered per-document processing
- `classify_document`
- `create_review_suggestion`
- manual document processing

The only exception is the initial embedding/indexing pipeline itself.

## Required State Model

Recommended table:

```text
embedding_index_state
- id
- status                  pending | building | complete | failed | stale
- embedding_model
- dimensions
- content_scope
- started_at
- completed_at
- document_count
- embedded_count
- failed_count
- error
- created_at
- updated_at
```

## Required Events

```text
embedding_index.pending
embedding_index.started
embedding_index.progress
embedding_index.completed
embedding_index.failed
embedding_index.marked_stale
pipeline.blocked.embedding_index_not_ready
```

## Required Helper

All actors that can start document processing must call a shared helper before doing any heavy work.

Recommended helper:

```python
ensure_embedding_index_ready()
```

Expected behavior:

- if `embedding_index_state.status == complete`, continue
- otherwise mark the pipeline run or delivery as blocked/pending
- emit `pipeline.blocked.embedding_index_not_ready`
- do not fetch, OCR, embed, classify or create suggestions for the document
- do not spin or busy-wait inside the worker

## Reindex Interaction

A full reindex closes the gate.

```text
reindex_started
  -> embedding_index_state.status = building | stale
  -> document processing blocked
  -> rebuild embeddings
  -> embedding_index_state.status = complete
  -> pending webhook deliveries / polling discoveries / commands can continue
```

Webhook ingestion remains allowed while the gate is closed.

## UI Requirements

Laravel should show:

- embedding index status
- build progress
- last successful completion time
- failed document count
- whether document processing is currently blocked
- count of pending webhook deliveries or pipeline runs blocked by embedding readiness

## Test Requirements

Minimum tests:

- webhook delivery is accepted while embedding index is `pending`
- webhook delivery is deduplicated while embedding index is `pending`
- document pipeline does not start while embedding index is `pending`
- polling does not start document processing while embedding index is `pending` or `building`
- manual process document command is blocked while embedding index is `pending`
- initial embedding completion releases pending work
- full reindex sets state to `building` or `stale` and blocks processing
- failed embedding build keeps processing blocked

## Related ADR

- `docs/decisions/0006-require-complete-embedding-index-before-document-processing.md`
