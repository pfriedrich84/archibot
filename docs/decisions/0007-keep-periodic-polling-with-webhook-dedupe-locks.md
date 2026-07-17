# ADR-0007: Keep Periodic Polling With Webhook Dedupe Locks

## Status

Accepted

## Context

Paperless webhooks are the primary trigger for document processing because they provide near-real-time document events.

However, periodic polling remains useful and should continue to run automatically, currently every 600 seconds. Polling catches missed webhook deliveries, startup gaps, network issues, manually changed documents, and reconciliation cases.

Running webhooks and polling together introduces a risk: the same document can be discovered by a webhook and by a poll cycle at nearly the same time. Without a shared dedupe and lock model this can cause duplicate processing, duplicate review suggestions, race conditions, and unnecessary LLM/embedding work.

## Decision

Archibot keeps automatic polling every 600 seconds as a reconciliation/fallback mechanism.

Webhooks remain the primary low-latency trigger, but polling is allowed to enqueue document pipelines when it discovers missing, stale or unprocessed documents.

Webhook-triggered and polling-triggered processing must share the same locking, idempotency and dedupe model.

Hard rule:

```text
A document may only have one active processing pipeline for the same effective content state.
```

## Required Locking Model

### Global Embedding Gate

No webhook-triggered or polling-triggered document processing may start before the embedding index is complete.

```text
embedding_index_state.status == complete
```

### Document Processing Lock

Every document-processing entrypoint must acquire the same document lock before starting a pipeline.

```text
archibot:document:{paperless_document_id}
```

This applies to:

- webhook-triggered processing
- polling/reconciliation-triggered processing
- manual processing
- retry processing

### Pipeline Dedupe Key

Both webhook and polling paths must compute or converge on the same dedupe key for a document/content state.

Recommended key:

```text
paperless_document_id + paperless_modified + content_hash + pipeline_version
```

If `content_hash` is not available yet, use:

```text
paperless_document_id + paperless_modified + pipeline_version
```

and update/confirm with `content_hash` once fetched.

### Unique Constraint

The persistent pipeline state must enforce uniqueness.

Recommended constraint:

```text
unique(paperless_document_id, pipeline_dedupe_key)
```

or equivalent on a dedicated `document_processing_runs` table.

## Source Semantics

Pipeline runs should record the trigger source:

```text
trigger_source = webhook | poll | manual | retry | reindex
```

If a poll discovers a document that is already queued or running because of a webhook, it must not create another active run. It may update metadata or add a `poll.coalesced_with_existing_run` event.

If a webhook arrives while a poll-triggered run is already active for the same document/content state, it must not start another run. It may attach the webhook delivery to the existing run or add a `webhook.coalesced_with_existing_run` event.

## Polling Behavior

Polling remains scheduled automatically every 600 seconds unless configured otherwise.

Polling should:

- run as reconciliation, not as the only main processing path;
- skip documents that have an active processing run;
- treat the existence of a durable Review Suggestion as the Classification Marker that the Inbox Document has already completed classification;
- skip an Inbox Document with a Classification Marker even when its Paperless `modified` value changed after review or metadata commit;
- enqueue processing only for documents without a Classification Marker or for failed states that are safe to retry;
- let explicit forced polls and manual force reprocess bypass the poll-only Classification Marker and create force-new Pipeline Runs, while leaving webhook action policy unaffected;
- emit events for skipped/coalesced/queued documents;
- respect the embedding readiness gate;
- respect reindex/global locks.

The Classification Marker is deliberately based on durable ArchiBot output rather than Paperless `modified`. ArchiBot review commits change Paperless metadata and therefore its modified timestamp; using that timestamp alone causes a committed Inbox Document to look new again when `KEEP_INBOX_TAG=true`. A rejected Review Suggestion also remains a valid marker because rejection means the classification was handled, not that polling should repeat it.

Recommended poll interval setting:

```env
POLL_INTERVAL_SECONDS=600
```

## Webhook Behavior

Webhooks should:

- be accepted quickly;
- persist `webhook_deliveries`;
- dedupe delivery-level duplicates;
- attempt to start or attach to a document pipeline;
- respect the same document lock and pipeline dedupe key as polling;
- remain pending/blocked if the embedding index is not complete.

## Reindex Interaction

A full reindex closes the embedding gate and blocks both webhook-triggered and polling-triggered processing.

Webhook deliveries may continue to be stored while reindex is active. Polling may continue to run for monitoring/reconciliation, but it must not start document pipelines while the gate is closed.

## Consequences

What gets easier:

- Webhooks provide quick processing.
- Polling still protects against missed webhook events.
- Duplicate processing is avoided by a shared lock/dedupe model and a durable Classification Marker for completed classification.
- Operational behavior remains familiar because the 600-second poll continues.

What gets harder:

- Pipeline-start logic must be centralized.
- Webhooks and polling must not each implement their own processing start behavior.
- The UI should explain whether a document was triggered by webhook, poll, manual action or retry.

What must not be done anymore:

- Starting document pipelines directly from separate webhook and polling code paths.
- Having different dedupe rules for webhook and poll.
- Letting polling bypass the embedding readiness gate.
- Letting webhooks bypass the document lock.
