# ADR-0006: Require Complete Embedding Index Before Document Processing

## Status

Accepted

## Context

Archibot uses existing documents and their embeddings as context for classification, review suggestions, similarity search and later RAG-style reasoning.

If document processing starts before the initial embedding index is complete, early documents may be classified with incomplete context. This can produce unstable suggestions, inconsistent review decisions and non-reproducible results depending on processing order.

The event-driven target architecture uses Paperless webhooks as the primary trigger. Webhooks can arrive immediately after startup or during migration. Therefore webhook ingestion and document processing must be separated by a hard readiness gate.

## Decision

Archibot must complete the embedding index before any document processing pipeline is allowed to run.

This is a hard global invariant:

```text
No document processing before embedding_index.status = complete
```

Webhook deliveries may still be received, validated, deduplicated and persisted before the embedding index is complete. They must not start document processing until the embedding gate is open.

Allowed before embedding completion:

- Receive Paperless webhook deliveries.
- Validate webhook payloads.
- Persist `webhook_deliveries`.
- Deduplicate webhook deliveries.
- Create pending commands or pending pipeline runs.
- Show readiness/status in Laravel UI.
- Start or resume the initial embedding build.

Blocked before embedding completion:

- `start_document_pipeline`
- `fetch_document` for processing purposes
- `correct_ocr`
- `embed_document` for new webhook-triggered processing
- `classify_document`
- `create_review_suggestion`
- manual document processing
- reconciliation document processing

The only exception is the initial embedding/indexing pipeline itself.

## Required Data Model

Add a durable embedding index state table or equivalent model in PostgreSQL.

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

Recommended events:

- `embedding_index.pending`
- `embedding_index.started`
- `embedding_index.progress`
- `embedding_index.completed`
- `embedding_index.failed`
- `embedding_index.marked_stale`

## Required Gate Behavior

Every actor that can start document processing must check the embedding gate before doing work.

Recommended helper:

```text
ensure_embedding_index_ready()
```

If the gate is closed, the actor must not process the document. It should either:

1. leave the command/pipeline run in a pending/blocked state, or
2. reschedule itself after a safe delay, or
3. emit a `pipeline.blocked.embedding_index_not_ready` event.

The preferred behavior is to persist the blocked state and retry via a controlled scheduler/reconciliation actor, not to spin aggressively in the worker.

## Locking Rules

### Embedding Build Lock

Only one initial embedding build or full embedding rebuild may run at a time.

Recommended lock key:

```text
archibot:embedding-index:build
```

### Embedding Readiness Gate

All document pipelines must check the durable readiness state before starting.

Recommended state check:

```text
embedding_index_state.status == complete
```

### Reindex Interaction

A full reindex sets the embedding index state to `building` or `stale` and blocks document processing until the rebuild is complete.

Reindex therefore blocks:

- webhook-triggered document processing
- manual document processing
- reconciliation document processing
- classification
- review suggestion generation

Webhook ingestion itself remains allowed.

## Webhook Interaction

Paperless webhooks remain the primary trigger, but they are split into two stages:

```text
Webhook ingestion: always allowed
Document processing: only allowed after embedding index is complete
```

Before the embedding index is complete:

```text
Paperless Webhook
  -> validate
  -> persist webhook_delivery
  -> dedupe
  -> mark as pending/blocked
  -> do not start document pipeline
```

After the embedding index is complete:

```text
pending webhook deliveries
  -> start_document_pipeline
  -> fetch_document
  -> correct_ocr
  -> embed_document
  -> classify_document
  -> create_review_suggestion
```

## Repository Governance Impact

Coding agents must treat this as a non-negotiable invariant.

AGENTS.md and the implementation plan should state:

```text
Document processing must never start before the embedding index is complete. Webhooks may be ingested before readiness, but they must remain pending or blocked until the embedding gate opens.
```

Review checklist addition:

- Does this change allow document processing before `embedding_index_state.status = complete`?
- Are webhook deliveries safely persisted while processing is blocked?
- Does reindex correctly close the embedding gate and reopen it only after completion?
- Are blocked pipeline runs visible in the UI?

## Consequences

What gets easier:

- Classification results are more stable.
- Review suggestions are based on complete context.
- Startup behavior is deterministic.
- Webhook timing no longer affects processing quality.

What gets harder:

- First startup requires an explicit embedding build before processing starts.
- The UI must show an embedding readiness state.
- Webhook deliveries can accumulate while the gate is closed.
- Reindex must coordinate with document processing.

What must not be done anymore:

- Starting webhook-triggered document processing while the embedding index is incomplete.
- Letting manual document processing bypass the embedding gate.
- Treating embedding build as just another optional background job.
- Running full reindex without closing the document-processing gate.
