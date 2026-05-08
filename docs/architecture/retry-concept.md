# Retry Concept

## Purpose

Archibot needs a clear retry concept for failed or interrupted work.

Retries must be safe across:

- worker crashes
- container rebuilds
- Paperless outages
- Ollama/LiteLLM outages
- transient network failures
- per-document processing errors
- partial reindex failures
- webhook/poll race conditions

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
8. Retry must respect embedding readiness, document locks and reindex locks.

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

- RabbitMQ was unavailable
- embedding gate was closed
- temporary DB/broker issue after persistence

## Retry Modes

### Automatic Retry

Used for transient failures.

Examples:

- HTTP timeout
- Paperless unavailable
- Ollama unavailable
- LiteLLM timeout
- HTTP 429 with retry-after
- broker reconnect issue

### Manual Retry

User-triggered from Laravel UI.

Examples:

- document failed after max retries
- permanent upstream issue was fixed
- operator wants to retry failed items from a reindex

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
| LiteLLM timeout | transient_provider | automatic |
| HTTP 429 | rate_limited | automatic with provider backoff |
| Invalid webhook payload | permanent_validation | no auto retry |
| Document deleted | permanent_missing_document | no auto retry by default |
| Embedding index not ready | blocked_embedding_index | blocked, not failure |
| Document lock held | blocked_document_lock | coalesce/pending |
| Unhandled exception | bug_unexpected | bounded retry then failed |

## Idempotency Requirements

Retry must not duplicate outputs.

Use dedupe keys for outputs:

### Document Pipeline Dedupe

```text
paperless_document_id + paperless_modified + content_hash + pipeline_version
```

### Review Suggestion Dedupe

```text
paperless_document_id + content_hash + model_version + prompt_version + suggestion_type
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

## Retry Flow: Actor Failure

```text
actor starts
  -> writes actor_execution running
  -> fails with retryable error
  -> writes actor_execution retrying
  -> writes pipeline event actor.retry_scheduled
  -> sets next_retry_at
  -> Dramatiq requeues with backoff
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

If RabbitMQ is unavailable after persisting delivery:

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

UI labels examples:

```text
Retrying in 2 minutes: Paperless unavailable
Failed after 5 attempts: Ollama timeout
Blocked: embedding index not complete
Retry failed 3 documents
Force reprocess document
```

## Worker Requirements

All retryable actors must:

- classify errors
- update actor execution status
- update durable progress/item state
- emit pipeline event
- log structured error with correlation IDs
- avoid duplicate outputs
- respect cancel_requested before retrying next step

## Test Requirements

Minimum tests:

- retryable Paperless timeout schedules retry
- retryable Ollama timeout schedules retry
- HTTP 429 uses retry-after if present
- invalid webhook payload becomes failed_permanent
- manual retry of failed document does not duplicate review suggestion
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
