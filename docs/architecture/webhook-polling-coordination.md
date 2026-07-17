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

Webhook and polling must not implement separate Pipeline Start decisions. ADR-0017 makes this Laravel Module the sole owner:

```text
App\Services\Pipeline\DocumentPipelineStarter::start(...)
```

Productive Python Pipeline Start has been deleted. Python polling persists protocol-v1 durable candidates; a lease-fenced Laravel consumer invokes the starter, which computes the dedupe key, enforces the embedding readiness gate, coalesces through durable PostgreSQL state, emits canonical events and dispatches only newly created runs. Run creation commits before fallible dispatch, including webhook recovery, so enqueue failure leaves a `pending` run for Laravel recovery. The post-dispatch `pending` to `queued` update is conditional and cannot overwrite a fast child's `running` or terminal transition.

Cross-runtime contract vectors remain normalization evidence and ownership guards reject reintroduction of productive Python creation/start paths.

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

The uniqueness constraint and coalesced-source update serialize duplicate starts. In addition, a stable PostgreSQL session advisory lock fences execution against embedding mutation. Each productive Python document child owns a shared lease from readiness revalidation through the complete actor mutation; each Python build/reindex child owns an exclusive lease before its first stale/build transition through completion. Laravel holds only short start/stale-transition leases and never waits for a child while holding one, so parent death cannot release a live child's protection.

### Webhook Delivery Dedupe

Webhook-level duplicate deliveries are still deduped separately:

```text
source + event_type + paperless_document_id + paperless_modified + payload_hash
```

Webhook delivery dedupe prevents duplicate deliveries. Pipeline dedupe prevents duplicate processing.

## Polling Behavior

Polling should:

- run automatically every 600 seconds by default
- discover Inbox Documents as reconciliation candidates
- load Classification Markers in one durable PostgreSQL query before starting document pipelines
- skip every Inbox Document that already has a Review Suggestion, regardless of Review Suggestion status or later Paperless metadata changes
- respect embedding readiness gate
- coalesce if an unmarked document already has an active run for the same content state
- enqueue only unmarked or retryable document pipelines
- emit `poll.document.skipped_already_classified` for marker skips and summary counts for skipped/coalesced/started documents
- not bypass document locks

The Review Suggestion is the Classification Marker because it is persisted only after classification succeeds. Paperless `modified` remains part of pipeline-run dedupe for races and unmarked documents, but it is not the poll completion marker: an ArchiBot commit changes Paperless metadata and can change `modified` while `KEEP_INBOX_TAG=true`. Explicit forced polls and manual force reprocess bypass this poll-only marker and create force-new Pipeline Runs; webhook action policy is unaffected.

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
Poll persists candidate for document #123 at the same time
Both reach Laravel DocumentPipelineStarter::start(...)
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
- a Review Suggestion marks an Inbox Document as classified and later polls skip it even after Paperless `modified` changes
- rejected and committed Review Suggestions remain valid Classification Markers
- an explicit forced poll bypasses Classification Markers and creates force-new Pipeline Runs
- unmarked documents with the same content state still coalesce through the shared pipeline-start seam
- new modified timestamp creates a new pipeline run for creation/consume webhook events
- lock conflict does not fail the pipeline permanently
- embedding gate blocks both webhook and poll processing
- polling interval default remains 600 seconds

## Related ADR

- `docs/decisions/0007-keep-periodic-polling-with-webhook-dedupe-locks.md`
