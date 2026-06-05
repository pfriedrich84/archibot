# Retry Concept

## Purpose

Archibot needs a clear retry and reprocess concept for failed, interrupted or intentionally repeated work.

Retries must be safe across:

- worker crashes
- container rebuilds
- Paperless outages
- Ollama-compatible/OpenAI-compatible provider outages
- transient network failures
- per-document processing errors
- partial reindex failures
- webhook/poll race conditions

Reprocess must be possible per document, even if the latest previous run succeeded.

The retry concept builds on durable state:

- `pipeline_runs`
- `pipeline_items`
- `actor_executions`
- `pipeline_events`
- `webhook_deliveries`
- durable progress fields
- pipeline/document dedupe keys

## Principles

1. Retry is state-based, not log-based.
2. Retry must be idempotent.
3. Retrying must not create duplicate review suggestions.
4. Retrying must not double-count progress.
5. Transient failures can auto-retry.
6. Permanent validation failures do not auto-retry.
7. User/manual retry is possible for failed but recoverable runs/items.
8. Per-document reprocess is always possible for admins.
9. Retry/reprocess must respect embedding readiness, document locks and reindex locks.

## Retry vs Reprocess

### Retry

Retry continues or repeats failed/missing work for a previous failed or interrupted run.

Examples:

- retry failed OCR step
- retry failed classification actor
- retry failed items in a reindex
- retry webhook delivery that was persisted but not enqueued

### Reprocess

Reprocess intentionally creates a new document-processing run for one document, even if previous runs succeeded.

Examples:

- admin wants a fresh classification after prompt/model changes
- admin wants to force new review suggestions
- admin wants to re-evaluate a document after manual Paperless corrections
- admin wants to re-run the full pipeline for debugging

Hard rule:

```text
Per-document reprocess must be available as an admin-only Laravel action.
```

Reprocess should create a new run with a new pipeline version, reprocess reason or force flag so it does not get suppressed by normal completed-run dedupe.

## Retry Levels

Archibot supports retries at different levels.

### Actor Retry

Retries one failed actor execution.

Examples:

- Paperless fetch failed
- Ollama classification timed out
- embedding request failed

Used for transient errors.

### Document Pipeline Retry

Retries one document pipeline.

Examples:

- document classification failed after max actor retries
- OCR correction failed for one document
- user manually retries a failed document

### Document Reprocess

Creates a new document pipeline run even if a previous run succeeded.

Required behavior:

- admin-only via Laravel UI/API
- check `is_admin()` before command creation
- acquire the same document lock
- respect embedding readiness gate
- record `trigger_source = manual`
- record `reprocess_requested = true`
- record `reprocess_reason` if provided
- create a new `pipeline_run_id`
- use a new or forced `pipeline_dedupe_key`
- avoid duplicate active runs for the same document
- decide whether to reuse existing embeddings or recompute them based on selected mode

Recommended modes:

```text
reprocess_metadata_only
reprocess_classification_only
reprocess_full_document_pipeline
reprocess_full_document_pipeline_force_embeddings
```

### Pipeline Item Retry

Retries one item in a batch-like parent run.

Examples:

- one document failed during reindex
- one document failed during polling/reconciliation

### Parent Pipeline Retry

Retries a full parent operation or only failed children depending on mode.

Examples:

- retry failed items in reindex
- retry failed items in reconciliation
- restart failed embedding build

### Webhook Delivery Retry

Retries a stored webhook delivery that could not be enqueued or normalized.

Examples:

- Absurd was unavailable
- embedding gate was closed
- temporary DB/Absurd enqueue issue after persistence

## Retry Modes

### Automatic Retry

Used for transient failures.

Examples:

- HTTP timeout
- Paperless unavailable
- Ollama unavailable
- OpenAI-compatible provider timeout
- HTTP 429 with retry-after
- Absurd/PostgreSQL reconnect issue

### Manual Retry

User-triggered from Laravel UI.

Examples:

- document failed after max retries
- permanent upstream issue was fixed
- operator wants to retry failed items from a reindex

### Manual Reprocess

Admin-triggered from Laravel UI.

Examples:

- reprocess one document from the document detail page
- force reprocess one document after changing model/prompt/config
- full pipeline rerun for one document

### Recovery Retry

System-triggered after restart/rebuild.

Examples:

- actor was `running` during container crash
- pipeline was `retrying` but retry message was lost
- webhook delivery was persisted but not enqueued

### No Retry

Used for permanent validation/input failures.

Examples:

- invalid webhook payload without document id
- unsupported event type
- document intentionally deleted
- schema validation failure that cannot be fixed by waiting

## Retry State Model

### `pipeline_runs`

Recommended fields:

```text
retry_count
max_retries
next_retry_at
last_retry_at
retry_reason
retry_mode          automatic | manual | recovery
retry_of_run_id
reprocess_requested
reprocess_reason
reprocess_mode
reprocess_of_run_id
```

### `pipeline_items`

Recommended fields:

```text
attempt
max_attempts
next_retry_at
last_retry_at
retry_reason
retry_mode
```

### `actor_executions`

Recommended fields:

```text
attempt
max_attempts
next_retry_at
last_retry_at
retry_reason
retry_mode
```

## Retry Statuses

Use explicit statuses:

```text
queued
running
retrying
failed
failed_permanent
succeeded
cancel_requested
cancelled
blocked
```

`blocked` is not retry failure. Examples:

- embedding index not ready
- reindex lock active
- document lock currently held

## Retry Backoff

Recommended default:

```text
30s -> 2m -> 5m -> 15m -> 30m
```

Provider-specific rules:

- If HTTP `Retry-After` exists, prefer it.
- For provider 429, use provider/model-specific backoff.
- For local Ollama unavailable, use bounded retry and then manual retry.

## Retry Classification

Recommended error classes:

```text
transient_network
transient_provider
transient_paperless
rate_limited
recoverable_processing
permanent_validation
permanent_missing_document
bug_unexpected
blocked_embedding_index
blocked_document_lock
cancelled
```

Mapping examples:

| Error | Class | Retry |
|---|---|---|
| Paperless timeout | transient_paperless | automatic |
| Paperless 503 | transient_paperless | automatic |
| Ollama connection refused | transient_provider | automatic |
| OpenAI-compatible provider timeout | transient_provider | automatic |
| HTTP 429 | rate_limited | automatic with provider backoff |
| Invalid webhook payload | permanent_validation | no auto retry |
| Document deleted | permanent_missing_document | no auto retry by default |
| Embedding index not ready | blocked_embedding_index | blocked, not failure |
| Document lock held | blocked_document_lock | coalesce/pending |
| Unhandled exception | bug_unexpected | bounded retry then failed |

## Idempotency Requirements

Retry must not duplicate outputs. Reprocess may intentionally create a new run/output set, but it must still be traceable and deduplicated within that reprocess run.

Use dedupe keys for outputs:

### Document Pipeline Dedupe

Normal run:

```text
paperless_document_id + paperless_modified + content_hash + pipeline_version
```

Forced reprocess run:

```text
paperless_document_id + paperless_modified + content_hash + pipeline_version + reprocess_run_id
```

### Review Suggestion Dedupe

Normal run:

```text
paperless_document_id + content_hash + model_version + prompt_version + suggestion_type
```

Forced reprocess run:

```text
paperless_document_id + content_hash + model_version + prompt_version + suggestion_type + reprocess_run_id
```

### Embedding Dedupe

```text
paperless_document_id + content_hash + embedding_model + dimensions
```

### Webhook Delivery Dedupe

```text
source + event_type + paperless_document_id + paperless_modified + payload_hash
```

## Progress and Retry

Progress must not double-count.

Bad:

```text
retry item -> progress_done += 1 again
```

Good:

```text
pipeline_items.status = succeeded once
progress_done = count(succeeded items)
```

For embedding builds:

```text
progress_done = count(document_embeddings matching build scope/model/content_hash)
```

For document reprocess:

```text
new pipeline_run_id -> new progress counters
previous runs remain unchanged and audit-visible
```

## Retry Flow: Actor Failure

```text
actor starts
  -> writes actor_execution running
  -> fails with retryable error
  -> writes actor_execution retrying
  -> writes pipeline event actor.retry_scheduled
  -> sets next_retry_at
  -> Absurd requeues with backoff
```

After max attempts:

```text
actor failed max attempts
  -> actor_execution failed
  -> pipeline item failed or pipeline run failed
  -> UI shows manual retry option
```

## Retry Flow: Manual Document Retry

```text
user clicks retry document
  -> create retry command or retry run
  -> preserve link to failed run
  -> compute same or updated dedupe key
  -> acquire document lock
  -> skip already succeeded outputs
  -> retry failed/missing steps
```

Manual retry should not blindly rerun everything unless explicitly requested.

Recommended modes:

```text
retry_failed_steps_only
retry_full_document_pipeline
force_reprocess_with_new_pipeline_version
```

## Reprocess Flow: Per Document

```text
admin clicks Force reprocess on document
  -> Laravel checks is_admin()
  -> create reprocess command
  -> create new pipeline_run_id
  -> set trigger_source = manual
  -> set reprocess_requested = true
  -> set reprocess_mode
  -> acquire document lock
  -> check embedding readiness gate
  -> start document pipeline
  -> produce new events/progress/output tied to the new run
```

If another run is active for the same document, the reprocess request must not start in parallel. It should either:

- stay queued/pending, or
- show a clear UI message, or
- attach to/cancel existing run only if the admin explicitly chooses that behavior.

## Retry Flow: Reindex Partial Failure

```text
reindex parent run partially_failed
  -> failed pipeline_items visible in UI
  -> user clicks retry failed items
  -> create retry command
  -> enqueue only failed items
  -> progress starts with failed subset total
```

Example:

```text
Reindex: 127 succeeded, 3 failed
Retry failed items: 0 / 3
Retry failed items: 3 / 3 succeeded
```

## Retry Flow: Webhook Delivery

If Absurd is unavailable after persisting delivery:

```text
webhook_delivery.status = queued or retry_pending
recovery/scheduler finds it
attempt enqueue again
on success -> status queued/processed depending stage
```

If webhook normalization fails permanently:

```text
webhook_delivery.status = failed_permanent
no automatic retry
UI may allow dismiss or manual retry after fix
```

## Recovery Retry After Reboot

On startup:

```text
scan running actor_executions older than threshold
scan pipeline_runs running/retrying without active actor
scan webhook_deliveries queued/blocked/retry_pending
scan pipeline_items running without active actor
```

Then:

```text
if safe and retryable -> requeue
if max retries exceeded -> failed
if blocked -> keep blocked
if cancel_requested -> cancelled
```

## UI Requirements

Laravel should expose:

- retry count
- max retries
- next retry time
- retry mode
- retry reason
- error class
- manual retry button where allowed
- retry failed items button for batch runs
- force reprocess option for document pipeline where appropriate
- per-document reprocess action on the document detail page
- reprocess mode selector where useful

UI labels examples:

```text
Retrying in 2 minutes: Paperless unavailable
Failed after 5 attempts: Ollama timeout
Blocked: embedding index not complete
Retry failed 3 documents
Force reprocess document
Reprocess classification only
Reprocess full document pipeline
```

## Worker Requirements

All retryable/reprocessable actors must:

- classify errors
- update actor execution status
- update durable progress/item state
- emit pipeline event
- log structured error with correlation IDs
- avoid duplicate outputs unless a force reprocess run intentionally creates a new output set
- respect cancel_requested before retrying next step
- respect document lock and embedding readiness gate

## Test Requirements

Minimum tests:

- retryable Paperless timeout schedules retry
- retryable Ollama timeout schedules retry
- HTTP 429 uses retry-after if present
- invalid webhook payload becomes failed_permanent
- manual retry of failed document does not duplicate review suggestion
- admin can force reprocess a succeeded document
- non-admin cannot force reprocess a document
- reprocess creates a new pipeline run linked to the previous document/run context
- reprocess does not run in parallel with an active document run
- retry of completed pipeline item does not double-count progress
- reindex retry only failed items works
- reboot recovery requeues stuck retryable work
- cancel_requested prevents retry continuation
- blocked embedding gate does not count as failed retry

## Related Documents

- `docs/architecture/failure-retry-recovery.md`
- `docs/architecture/progress-tracking.md`
- `docs/architecture/webhook-polling-coordination.md`
- `docs/architecture/embedding-readiness-gate.md`
