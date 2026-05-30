# Reprocess Triggers

## Purpose

Per-document reprocess must be possible in two ways:

```text
manual   -> admin clicks a Laravel button
webhook  -> Paperless reports a relevant document change
```

Both paths must use the same embedding readiness gate, pipeline dedupe logic, and durable unique `(paperless_document_id, pipeline_dedupe_key)` coalescing seam.

## Manual Reprocess

Manual reprocess is an admin job-control action.

Required Laravel behavior:

- show a per-document reprocess button on the document detail page for admins
- recommended button label: `Force reprocess`
- optionally offer a mode selector
- require `is_admin()` in the backend before creating the command
- create a new pipeline run
- set `trigger_source = manual`
- set `reprocess_requested = true`
- set `reprocess_mode`
- record `requested_by_user_id`

Recommended modes:

```text
reprocess_metadata_only
reprocess_classification_only
reprocess_full_document_pipeline
reprocess_full_document_pipeline_force_embeddings
```

Manual force reprocess always creates a new run, even if the latest previous run succeeded and the effective content state is identical. Attach/retry existing is a separate non-force mode.

## Webhook-triggered Processing

Webhook-triggered processing is automatic and comes from Paperless.

Required behavior:

- no `is_admin()` check because this is not a user action
- webhook security still applies
- persist and deduplicate the webhook delivery
- route `created`/`added`/`consumed` events into the full document pipeline
- route `updated`/`changed`/`modified` events into embedding refresh only, not full reclassification
- route `deleted`/`trashed` events into embedding cleanup
- compute the document/content-state dedupe key for full document pipeline starts
- acquire or respect the durable pipeline coalescing seam for full document pipeline starts
- check the embedding readiness gate
- coalesce with an active run for the same document/content state
- set `trigger_source = webhook` for full document pipeline starts
- link `webhook_delivery_id`

Webhook-triggered processing must not force duplicate full runs for duplicate webhook deliveries or unchanged document state. Paperless edit/update webhooks must not create classification feedback loops after ArchiBot writes metadata back to Paperless.

## Shared Pipeline Start

Both manual and webhook-triggered reprocess must call the same shared pipeline-start logic:

```text
start_or_attach_document_pipeline(trigger_source, paperless_document_id, paperless_modified, content_hash?)
```

Responsibilities:

1. check embedding readiness gate
2. compute pipeline dedupe key
3. use the durable unique `(paperless_document_id, pipeline_dedupe_key)` coalescing seam
4. check existing active/completed run
5. create new run or coalesce with existing run
6. enqueue next actor only if required
7. emit structured event

## Laravel Button Requirement

Admin users must have an explicit Laravel UI button/action for manual per-document reprocess.

Recommended placement:

```text
Document detail page
  -> Force reprocess
  -> optional mode selector
```

Non-admin users must not see or must not be able to use the action, and the backend must still return `403` if called directly.

## Event Examples

```text
job_control.force_reprocess_requested
pipeline.force_reprocess.requested
pipeline.start.pending
pipeline.start.coalesced
pipeline.blocked.embedding_index_not_ready
webhook.document_change_detected
```

## Test Requirements

Minimum tests:

- admin sees per-document `Force reprocess` action
- non-admin does not see or cannot use per-document `Force reprocess`
- backend requires `is_admin()` for manual reprocess
- manual reprocess creates a new pipeline run for a succeeded document
- webhook-triggered document change can create a new run
- duplicate webhook does not create duplicate reprocess run
- manual and webhook paths use the same document lock/dedupe helper
- neither path bypasses embedding readiness gate

## Related Documents

- `docs/architecture/retry-concept.md`
- `docs/architecture/authorization-job-control.md`
- `docs/architecture/webhook-polling-coordination.md`
- `docs/architecture/embedding-readiness-gate.md`
