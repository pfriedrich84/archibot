# ADR-0009: Use Structured Centralized Observability

## Status

Accepted

## Context

Archibot is moving to an event-driven architecture with multiple runtime components:

- Laravel UI / API / webhook ingestion
- Python Absurd workers
- Absurd broker
- PostgreSQL + pgvector
- Paperless integration
- Ollama / LiteLLM provider integration
- periodic polling / reconciliation
- reindex and embedding bootstrap pipelines

Debugging failures across these components requires more than local stdout logs. At the same time, Archibot must not confuse technical logs with business/audit events. Pipeline events in PostgreSQL are user-facing and durable. Logs are operational and primarily used for debugging and monitoring.

## Decision

Archibot uses structured centralized observability.

The system distinguishes four categories:

```text
pipeline_events   durable business/audit trail in PostgreSQL
structured logs   operational debugging logs, centralized
metrics           counters, gauges, durations, queue depth, error rates
traces            cross-service request/pipeline correlation where useful
```

Structured logs must include correlation identifiers so that a webhook delivery, pipeline run, actor execution and LLM call can be followed end-to-end.

## Required Correlation IDs

Every structured log should include relevant identifiers when available:

```text
request_id
webhook_delivery_id
command_id
pipeline_run_id
actor_execution_id
message_id
paperless_document_id
pipeline_dedupe_key
trigger_source
queue_name
actor_name
provider
model
```

## Logging Format

Logs should be JSON in container/runtime environments.

Recommended fields:

```text
timestamp
level
service
component
environment
message
request_id
webhook_delivery_id
pipeline_run_id
actor_execution_id
paperless_document_id
event_type
actor_name
queue_name
duration_ms
error_type
error_message
```

Local developer output may remain human-readable if useful, but production/container logs should be structured JSON.

## Central Log Sink

The target architecture should allow shipping container stdout/stderr to a central log system.

Recommended options:

- Grafana Loki + Promtail/Alloy
- OpenTelemetry Collector + compatible backend
- Docker logging driver to a central sink

For self-hosted Archibot, Loki is a pragmatic default.

## Pipeline Events vs Logs

Pipeline events are not a replacement for logs.

Pipeline events answer:

- What happened to this document?
- Why is this pipeline blocked?
- Which user-visible step failed?
- Can the user retry or cancel?

Structured logs answer:

- Which code path failed?
- Which HTTP call timed out?
- Which actor crashed?
- Which exception occurred?
- What did Absurd/Paperless/Ollama do around that time?

## Required Component Behavior

### Laravel

Laravel should emit structured logs for:

- webhook receipt
- webhook validation errors
- webhook dedupe decisions
- command creation
- UI-triggered retries/cancellations
- enqueue failures
- database errors

Laravel logs must include `request_id` and, if available, `webhook_delivery_id`, `pipeline_run_id`, and `paperless_document_id`.

### Python / Absurd

Python workers should emit structured logs for:

- actor start/success/failure/retry
- Paperless API calls
- LLM/Ollama/LiteLLM calls
- embedding build progress summaries
- lock acquisition/release/coalescing
- recovery scans

Python logs must include `message_id`, `actor_name`, `pipeline_run_id`, `actor_execution_id`, and `paperless_document_id` where available.

### Absurd

Absurd operational metrics and logs should be available for:

- queue depth
- task spawn/claim/completion/failure rates
- retrying or sleeping task counts
- oldest pending task age
- worker loop and PostgreSQL connection issues

### PostgreSQL

PostgreSQL logs are operational and should remain separate from Archibot pipeline events.

Important signals:

- connection failures
- long-running queries
- migration failures
- pgvector index build issues

## Metrics

Minimum metrics:

```text
webhook_deliveries_total
webhook_deliveries_failed_total
pipeline_runs_total
pipeline_runs_failed_total
pipeline_runs_blocked_total
actor_executions_total
actor_executions_failed_total
actor_retry_total
paperless_request_duration_ms
llm_request_duration_ms
embedding_build_progress
absurd_queue_depth
pending_webhook_deliveries
blocked_pipeline_runs
```

## Error Handling

Errors should be recorded in three places when appropriate:

1. `actor_executions` for technical execution state
2. `pipeline_events` for user-visible/audit state
3. structured logs for debugging detail

Do not put secrets, API keys or full sensitive document content into logs.

## Consequences

What gets easier:

- debugging across Laravel, Python and Absurd
- correlating webhook deliveries with actors and LLM calls
- monitoring retry loops and stuck runs
- operating Archibot after container restarts

What gets harder:

- all components must consistently pass correlation IDs
- logs need redaction rules
- local development needs sane defaults
- documentation must clarify logs vs events

What must not be done anymore:

- relying only on local console output
- logging unstructured free text without IDs
- putting user-sensitive document content or secrets into logs
- treating pipeline_events as the only debugging mechanism
