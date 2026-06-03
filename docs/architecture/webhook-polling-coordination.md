# Webhook and Polling Coordination

## Purpose

Paperless webhooks are the primary low-latency trigger for document processing.

Periodic polling remains enabled and should run automatically every 600 seconds as reconciliation/fallback.

Both sources must coordinate through the same pipeline-start logic so that the same document is not processed twice.

## Hard Rule

```text
A document may only have one active processing pipeline for the same effective content state.
```

This applies regardless of trigger source:

- webhook
- poll/reconciliation
- manual
- retry
- reindex

## Polling Interval

Default:

```env
POLL_INTERVAL_SECONDS=600
```

Polling should remain automatic unless explicitly disabled.

## Trigger Sources

Every pipeline run should record one trigger source:

```text
webhook
poll
manual
retry
reindex
```

If a later trigger coalesces with an already active run, write an event instead of creating a duplicate run.

## Webhook Action Policy

Laravel owns the Paperless webhook action policy at the ingestion seam. `App\Services\Webhooks\PaperlessWebhookNormalizer` maps Paperless event names to the persisted ArchiBot action in `webhook_deliveries.normalized_payload.webhook_action`:

```text
process_document
refresh_embedding
delete_embedding
```

Python actors must not derive this action from `event_type`. They validate and execute the persisted action. Missing or unknown actions are malformed persisted state and must mark the Webhook Delivery `failed_permanent` with `invalid_webhook_action` instead of falling back to event-type parsing. Because Webhook Deliveries are short-lived operational receipts, `webhook_action` remains JSON metadata rather than a separate query column.

Canonical start events:

```text
pipeline.start.pending
pipeline.start.coalesced
pipeline.start.attached
pipeline.blocked.embedding_index_not_ready
pipeline.force_reprocess.requested
```

## Shared Start Seam

Webhook and polling code must not each implement separate pipeline-start logic.

Current cross-runtime adapters for this Pipeline Run start interface are:

```text
Laravel: App\Services\Pipeline\DocumentPipelineStarter::start(...)
Python:  app.jobs.pipeline_start.start_or_attach_document_pipeline(...)
```

Both adapters must satisfy the same interface: compute the same dedupe key, enforce the embedding readiness gate, coalesce through durable PostgreSQL state, and emit the canonical Pipeline Run events. The shared contract vectors live in `tests/fixtures/pipeline_start_contract.json` and are exercised by both PHP and Python tests.

Deletion target: future work should remove duplicated start implementation by moving callers toward durable Command / Pipeline Run / Dramatiq actor seams, not by deepening Worker Job or subprocess paths.

Responsibilities:

1. Check embedding readiness gate.
2. Compute or look up the pipeline dedupe key.
3. Use the durable unique `(paperless_document_id, pipeline_dedupe_key)` constraint as the coalescing seam.
4. Check existing active/completed run for same document/content state.
5. Create new run or attach/coalesce with existing run.
6. Enqueue next actor only if a new run is needed.
7. Emit structured event.

## Dedupe Key

Recommended key:

```text
paperless_document_id + paperless_modified + content_hash + pipeline_version
```

If `content_hash` is not available yet:

```text
paperless_document_id + paperless_modified + pipeline_version
```

After fetching the document, the pipeline can confirm or update the content hash.

## Locking

### Embedding Readiness Gate

Both webhook and polling paths are blocked until:

```text
embedding_index_state.status == complete
```

### Document Lock

All trigger sources use the same durable coalescing seam:

```text
unique(paperless_document_id, pipeline_dedupe_key)
```

A future PostgreSQL advisory lock or lock table may still be added for finer-grained scheduling, but the implemented correctness seam is the database uniqueness constraint plus coalesced-source update.

### Webhook Delivery Dedupe

Webhook-level duplicate deliveries are still deduped separately:

```text
source + event_type + paperless_document_id + paperless_modified + payload_hash
```

Webhook delivery dedupe prevents duplicate deliveries. Pipeline dedupe prevents duplicate processing.

## Polling Behavior

Polling should:

- run automatically every 600 seconds by default
- discover recent or stale documents
- respect embedding readiness gate
- skip or coalesce if an active run exists
- skip if the latest content state already completed successfully
- enqueue only missing/stale/retryable document pipelines
- emit events for skipped/coalesced/queued documents
- not bypass document locks

## Webhook Behavior

Webhooks should:

- be accepted quickly
- persist delivery before enqueueing
- dedupe delivery-level duplicates
- route creation/consume events through the same pipeline start function as polling
- route edit/update events to embedding refresh only, not full reclassification
- respect embedding readiness gate
- coalesce creation/consume events with existing poll/manual/retry runs when appropriate

## Race Example: Webhook and Poll Arrive Together

```text
Webhook receives document #123
Poll discovers document #123 at same time
Both call start_or_attach_document_pipeline(...)
Only one creates the unique pipeline run
Other source attaches/coalesces through the unique constraint and emits an event
```

## Database Requirements

Implemented pipeline uniqueness:

```text
unique(paperless_document_id, pipeline_dedupe_key)
```

Recommended fields on `pipeline_runs`:

```text
trigger_source
paperless_document_id
paperless_modified
content_hash
pipeline_dedupe_key
coalesced_sources
```

## Test Requirements

Minimum tests:

- webhook and poll for same document create only one active run
- poll skips document already running from webhook
- webhook attaches to document already running from poll
- completed latest content state is skipped by poll
- new modified timestamp creates a new pipeline run for creation/consume events
- lock conflict does not fail the pipeline permanently
- embedding gate blocks both webhook and poll processing
- polling interval default remains 600 seconds

## Related ADR

- `docs/decisions/0007-keep-periodic-polling-with-webhook-dedupe-locks.md`
