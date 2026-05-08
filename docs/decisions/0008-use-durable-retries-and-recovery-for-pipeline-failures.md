# ADR-0008: Use Durable Retries and Recovery for Pipeline Failures

## Status

Accepted

## Context

Archibot's event-driven pipeline depends on several external and internal systems:

- PostgreSQL for durable state
- RabbitMQ for message transport
- Paperless for document metadata and content
- Ollama or LiteLLM providers for OCR/classification/LLM work
- container runtime / host availability

Failures are expected:

- container reboot or rebuild
- worker crash during processing
- RabbitMQ restart
- PostgreSQL restart
- Paperless temporarily unreachable
- Ollama/LiteLLM temporarily unreachable
- LLM timeout or provider rate limit
- malformed document data
- OCR/classification/embedding errors
- code bugs

The pipeline must be restart-safe and retryable without creating duplicate processing, duplicate review suggestions or hidden inconsistent state.

## Decision

Archibot uses durable pipeline state in PostgreSQL plus Dramatiq retry semantics for transient execution failures.

PostgreSQL is the source of truth for:

- webhook deliveries
- commands
- pipeline runs
- pipeline events
- actor executions
- document processing dedupe keys
- final failure state

RabbitMQ/Dramatiq is used for execution transport, not as the only source of job truth.

All actors must be idempotent and safe to retry.

## Failure Classes

### 1. Transient Infrastructure Failure

Examples:

- Paperless temporarily unreachable
- Ollama temporarily unreachable
- LiteLLM provider timeout
- HTTP 429 / 5xx
- network timeout
- RabbitMQ reconnect

Behavior:

- retry with exponential backoff
- keep pipeline run in `running` or `retrying`
- emit structured events
- do not create duplicate outputs

### 2. Recoverable Processing Failure

Examples:

- one document fails OCR
- one document fails classification
- one embedding request fails repeatedly
- invalid Paperless response for one document

Behavior:

- retry within configured limit
- mark actor execution as failed after retries
- mark document pipeline as `failed` or parent run as `partially_failed`
- allow manual retry from UI

### 3. Permanent Validation / Input Failure

Examples:

- webhook payload missing document id
- document no longer exists in Paperless
- unsupported document state
- malformed normalized payload

Behavior:

- no infinite retries
- mark webhook delivery or pipeline run as `failed_permanent`
- emit user-visible event with reason
- allow manual dismissal or reprocess if upstream data is fixed

### 4. Global Blocking State

Examples:

- embedding index not complete
- reindex active
- document lock held

Behavior:

- do not treat as failure
- mark as `blocked` or `pending`
- emit `pipeline.blocked.*` event
- release later through reconciliation/recovery actor

### 5. Code Bug / Unexpected Exception

Examples:

- uncaught exception
- schema mismatch
- serialization error

Behavior:

- retry only if safe and bounded
- preserve traceback/error summary in `actor_executions`
- move to failed state after retry limit
- require developer fix plus manual or automated retry

## Required Status Model

### Pipeline Run Statuses

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

### Actor Execution Statuses

```text
queued
running
retrying
succeeded
failed
failed_permanent
cancelled
```

### Webhook Delivery Statuses

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

Recommended default retry policy:

```text
Transient HTTP/network errors: retry 5 times with exponential backoff
Provider 429/rate limit: retry with provider-specific backoff
Paperless unavailable: retry 5 times, then leave run retryable/manual
Ollama unavailable: retry 5 times, then leave run retryable/manual
Validation error: no retry, failed_permanent
Embedding gate closed: no failure, blocked/pending
Document lock conflict: no failure, reschedule or coalesce
```

Recommended backoff shape:

```text
30s -> 2m -> 5m -> 15m -> 30m
```

The exact values can be made configurable.

## Container Reboot / Worker Crash Recovery

On startup, a recovery actor or maintenance command must inspect durable state and repair incomplete work.

Recovery should handle:

- actor executions stuck in `running`
- pipeline runs stuck in `running` or `retrying`
- webhook deliveries in `queued` or `blocked`
- commands in `queued` or `running`
- stale locks

Recommended startup recovery flow:

```text
worker/container starts
  -> run recovery scan
  -> mark stale actor_executions as retrying or failed
  -> release expired locks
  -> requeue safe pending/retryable work
  -> keep permanent failures unchanged
```

Recovery must respect:

- embedding readiness gate
- document locks
- pipeline dedupe keys
- cancellation requests
- max retry counts

## Durable Actor Execution Tracking

Every actor should create or update an `actor_executions` row.

Minimum fields:

```text
actor_name
message_id
pipeline_run_id
paperless_document_id
queue_name
status
attempt
started_at
finished_at
duration_ms
error_type
error_message
retry_at
```

## Cancellation

Cancellation is cooperative.

Rules:

- A cancel request sets pipeline status to `cancel_requested`.
- Actors check cancellation before starting and between heavy steps.
- Running LLM/OCR calls may finish naturally; the next step must not start.
- Cancelled runs emit `pipeline.cancelled`.
- Cancelled work must not be auto-retried unless explicitly requested.

## Partial Failure

Batch-like operations such as reconciliation or reindex should not fail completely because one document fails.

Rules:

- per-document pipeline can fail independently
- parent run aggregates results
- parent run becomes `partially_failed` if at least one document failed but others succeeded
- failed documents remain retryable

## UI Requirements

Laravel should show:

- run status
- current actor / phase
- last event
- retry count
- next retry time
- error class
- whether retry is automatic or manual
- cancellation state
- partial failure counts

Manual actions:

- retry failed document
- retry failed pipeline run
- cancel queued/running pipeline run
- dismiss permanent webhook delivery failure
- re-run reconciliation

## Consequences

What gets easier:

- container restarts do not lose work
- transient Paperless/Ollama/LiteLLM outages recover automatically
- failures are visible and actionable
- retries are safe because actors are idempotent

What gets harder:

- all actors must be designed for retry/idempotency
- state transitions must be explicit
- recovery logic must be tested
- UI needs to expose blocked/retrying/failed states clearly

What must not be done anymore:

- relying only on in-memory progress
- treating RabbitMQ messages as the only job state
- starting non-idempotent actors
- retrying validation/permanent failures forever
- hiding worker crashes behind generic failed logs
