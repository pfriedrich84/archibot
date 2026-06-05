# Failure, Retry and Recovery Architecture

## Purpose

Archibot must survive routine failures without losing work or creating duplicate processing.

Expected failures:

- container reboot or rebuild
- worker crash
- Absurd worker restart
- PostgreSQL restart
- Paperless unavailable
- Ollama unavailable
- OpenAI-compatible provider unavailable
- LLM timeout or rate limit
- document-level OCR/classification/embedding failure
- invalid webhook payload
- code bug

The system must be durable, observable and retryable.

## Principles

1. PostgreSQL is the source of truth for durable state.
2. Absurd is execution transport, not the only job state.
3. Every actor must be idempotent.
4. Every actor must write structured execution state and events.
5. Retrying must not create duplicate review suggestions or duplicate document processing.
6. Permanent input errors must not retry forever.
7. Blocked work is not failed work.
8. Container restart must be recoverable through a startup recovery scan.

## Failure Classes

### Transient Infrastructure Failure

Examples:

- Paperless HTTP timeout
- Paperless 502/503
- Ollama connection refused
- OpenAI-compatible provider timeout
- HTTP 429 / rate limit
- temporary network issue

Action:

- retry with exponential backoff
- keep pipeline visible as `retrying`
- emit event with error type and next retry time

### Recoverable Processing Failure

Examples:

- one document OCR fails
- one document embedding fails
- classification output parsing fails

Action:

- retry within limit
- after max retries mark document pipeline failed
- parent batch/reindex/reconciliation becomes `partially_failed` if other documents succeeded
- allow manual retry

### Permanent Input Failure

Examples:

- invalid webhook payload
- missing document id
- document deleted in Paperless
- unsupported normalized event

Action:

- mark `failed_permanent`
- do not auto-retry
- expose in UI
- allow manual dismissal or reprocess after upstream fix

### Blocking State

Examples:

- embedding index not complete
- reindex active
- document lock conflict
- cancellation requested

Action:

- mark `blocked`, `pending` or `cancel_requested`
- do not count as processing failure
- resume or stop through controlled recovery logic

## Status Model

### Pipeline Runs

```text
pending
blocked
queued
running
retrying
succeeded
partially_failed
failed
failed_permanent
cancel_requested
cancelled
```

### Actor Executions

```text
queued
running
retrying
succeeded
failed
failed_permanent
cancelled
```

### Webhook Deliveries

```text
received
duplicate
queued
blocked
processed
failed
failed_permanent
```

## Retry Policy

Recommended default:

```text
30s -> 2m -> 5m -> 15m -> 30m
```

Recommended behavior:

| Failure | Retry? | Final state |
|---|---:|---|
| Paperless timeout | yes | failed after max retries |
| Paperless unavailable | yes | failed after max retries |
| Ollama unavailable | yes | failed after max retries |
| OpenAI-compatible provider timeout | yes | failed after max retries |
| Provider 429 | yes, provider-specific backoff | failed after max retries |
| Invalid webhook payload | no | failed_permanent |
| Missing document id | no | failed_permanent |
| Embedding gate closed | no failure | blocked |
| Document lock conflict | no failure | coalesced or pending |
| Cancel requested | no retry | cancelled |
| Unexpected bug | bounded retry | failed |

## Container Reboot / Rebuild Recovery

On worker startup, run a recovery scan.

Recovery scan responsibilities:

- find actor executions stuck in `running`
- find pipeline runs stuck in `running` or `retrying`
- find webhook deliveries stuck in `queued` or `blocked`
- release expired locks
- requeue safe pending/retryable work
- keep permanent failures unchanged
- respect cancellation requests
- respect embedding readiness gate
- respect document dedupe keys

Recommended flow:

```text
container starts
  -> connect PostgreSQL
  -> connect Absurd
  -> run recovery scan
  -> mark stale running actor_executions retrying/failed
  -> requeue safe work
  -> start normal worker consumption
```

Recovery must be safe to run multiple times.

## Actor Execution Tracking

Every actor should create/update an `actor_executions` row.

Recommended fields:

```text
id
pipeline_run_id
paperless_document_id
actor_name
message_id
queue_name
status
attempt
max_attempts
started_at
finished_at
duration_ms
error_type
error_message
retry_at
created_at
updated_at
```

## Cancellation Model

Cancellation is cooperative.

Rules:

- User/API sets pipeline run to `cancel_requested`.
- Actors check cancellation before each heavy step.
- A running LLM/OCR request may finish naturally.
- No next actor is enqueued after cancellation.
- Final state becomes `cancelled`.
- Cancelled work is not auto-retried.

## Partial Failure Model

Batch-style operations include:

- periodic poll/reconciliation
- reindex
- bulk retry

Rules:

- each document has its own pipeline run or child run
- one failed document does not fail the whole batch
- parent run records succeeded/failed/skipped counts
- parent run becomes `partially_failed` if at least one document failed and at least one succeeded
- failed document runs remain retryable

## Paperless Unavailable

If Paperless is unavailable:

- webhook ingestion can still persist incoming payloads
- actors that need Paperless retry with backoff
- pipeline run remains visible as `retrying`
- after max retries it becomes `failed`
- manual retry is allowed

## Ollama / OpenAI-compatible Unavailable

If model provider is unavailable:

- classify/OCR actors retry with backoff
- provider errors are recorded in `llm_calls`
- rate limits use provider-specific retry-after if available
- after max retries document run becomes `failed`
- manual retry can use same or changed provider/model

## Absurd Unavailable

Webhook endpoint must not lose the delivery if Absurd is unavailable.

Required behavior:

```text
receive webhook
  -> persist webhook_delivery
  -> attempt enqueue
  -> if enqueue fails, mark delivery queued/retry_pending
  -> scheduler/recovery later enqueues it
```

The HTTP response strategy should be deliberate:

- for internal Paperless webhook, returning non-2xx can trigger Paperless retry if supported
- if Paperless retry behavior is unknown or unreliable, prefer persisting first and exposing retry_pending internally

## PostgreSQL Unavailable

If PostgreSQL is unavailable, Archibot cannot safely persist work.

Required behavior:

- webhook endpoint should fail closed with non-2xx
- actors should fail/retry
- no in-memory-only pipeline state should be accepted as durable

## UI Requirements

Laravel should expose:

- pipeline status
- current actor/phase
- last event
- retry count
- max retry count
- next retry time
- error type
- error message summary
- trigger source: webhook, poll, manual, retry, reindex
- cancellation state
- partial failure counts

Manual actions:

- retry failed document
- retry failed pipeline run
- cancel queued/running run
- dismiss permanent webhook failure
- re-run poll/reconciliation
- re-run embedding build if failed

## Test Requirements

Minimum tests:

- worker crash leaves durable actor execution state recoverable
- startup recovery requeues safe stuck work
- Paperless unavailable triggers retry, not duplicate pipeline
- Ollama unavailable triggers retry, not duplicate suggestion
- invalid webhook becomes failed_permanent
- Absurd enqueue failure after webhook persistence is recoverable
- PostgreSQL unavailable rejects webhook safely
- cancel_requested prevents next actor enqueue
- retry after failure respects document lock and dedupe key
- batch/reindex can become partially_failed

## Related ADR

- `docs/decisions/0008-use-durable-retries-and-recovery-for-pipeline-failures.md`
