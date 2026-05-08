# Reprocess Triggers

## Purpose

Per-document reprocess must be possible in two ways:

```text
manual   -> admin clicks a Laravel button
webhook  -> Paperless reports a relevant document change
```

Both paths must use the same document lock, embedding readiness gate and pipeline dedupe logic.

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

Manual reprocess may intentionally create a new run even if the latest previous run succeeded.

## Webhook-triggered Reprocess

Webhook-triggered reprocess is automatic and comes from Paperless.

Required behavior:

- no `is_admin()` check because this is not a user action
- webhook security still applies
- persist and deduplicate the webhook delivery
- compute the document/content-state dedupe key
- acquire or respect the document lock
- check the embedding readiness gate
- start a new run only if the document state is new, changed, missing or stale
- coalesce with an active run for the same document/content state
- set `trigger_source = webhook`
- link `webhook_delivery_id`

Webhook-triggered reprocess must not force duplicate runs for duplicate webhook deliveries or unchanged document state.

## Shared Pipeline Start

Both manual and webhook-triggered reprocess must call the same shared pipeline-start logic:

```text
start_or_attach_document_pipeline(trigger_source, paperless_document_id, paperless_modified, content_hash?)
```

Responsibilities:

1. check embedding readiness gate
2. compute pipeline dedupe key
3. acquire document lock
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
pipeline.reprocess_requested
pipeline.reprocess_started
webhook.document_change_detected
webhook.reprocess_coalesced_with_existing_run
pipeline.start_skipped_duplicate
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
