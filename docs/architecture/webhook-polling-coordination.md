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

Recommended events:

```text
webhook.coalesced_with_existing_run
poll.coalesced_with_existing_run
manual.coalesced_with_existing_run
pipeline.start_skipped_duplicate
```

## Shared Start Function

Webhook and polling code must not each implement separate pipeline-start logic.

Use a shared function/service, for example:

```text
start_or_attach_document_pipeline(trigger_source, paperless_document_id, paperless_modified, content_hash?)
```

Responsibilities:

1. Check embedding readiness gate.
2. Compute or look up the pipeline dedupe key.
3. Acquire document lock.
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

All trigger sources use the same document lock:

```text
archibot:document:{paperless_document_id}
```

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
- use the same pipeline start function as polling
- respect embedding readiness gate
- coalesce with existing poll/manual/retry runs when appropriate

## Race Example: Webhook and Poll Arrive Together

```text
Webhook receives document #123
Poll discovers document #123 at same time
Both call start_or_attach_document_pipeline(...)
Only one acquires document lock and creates active run
Other source attaches/coalesces and emits event
```

## Database Requirements

Recommended pipeline uniqueness:

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
- new modified timestamp creates a new pipeline run
- lock conflict does not fail the pipeline permanently
- embedding gate blocks both webhook and poll processing
- polling interval default remains 600 seconds

## Related ADR

- `docs/decisions/0007-keep-periodic-polling-with-webhook-dedupe-locks.md`
