# Observability and Centralized Logging

## Purpose

Archibot runs across Laravel, Python Dramatiq workers, RabbitMQ, PostgreSQL, Paperless and Ollama/LiteLLM providers.

Centralized structured observability is required to debug event-driven flows, retries, locks, webhook ingestion and provider outages.

## Observability Layers

Archibot separates four layers:

```text
pipeline_events   durable user-facing/audit events in PostgreSQL
structured logs   operational debugging logs, shipped centrally
metrics           counters, gauges, durations and queue depth
traces            optional cross-component timing/correlation
```

## Logs vs Pipeline Events

Pipeline events are product/audit state.

Examples:

- `webhook.received`
- `pipeline.blocked.embedding_index_not_ready`
- `classification.finished`
- `review_suggestion.created`
- `pipeline.failed`

Structured logs are operational debugging detail.

Examples:

- Laravel request accepted webhook in 42 ms
- Python actor started
- Paperless API call timed out
- Ollama request failed after 60 s
- RabbitMQ enqueue failed
- startup recovery scan requeued 4 runs

Both are needed.

## Central Log Sink

Recommended default for self-hosted Archibot:

```text
container stdout/stderr
  -> Promtail or Grafana Alloy
  -> Grafana Loki
  -> Grafana dashboards / log search
```

Alternative:

```text
container stdout/stderr
  -> OpenTelemetry Collector
  -> Loki / Tempo / other backend
```

The app should not require a central log stack to run locally. Local development may print human-readable logs. Container/production mode should use JSON logs.

## JSON Log Format

Recommended fields:

```json
{
  "timestamp": "2026-05-08T12:00:00.000Z",
  "level": "info",
  "service": "archibot-python-worker",
  "component": "dramatiq",
  "environment": "production",
  "message": "actor started",
  "request_id": "req_...",
  "webhook_delivery_id": 123,
  "command_id": 456,
  "pipeline_run_id": "run_...",
  "actor_execution_id": "exec_...",
  "message_id": "dramatiq-message-id",
  "paperless_document_id": 789,
  "pipeline_dedupe_key": "...",
  "trigger_source": "webhook",
  "queue_name": "archibot.llm",
  "actor_name": "classify_document",
  "event_type": "actor.started",
  "duration_ms": 42,
  "error_type": null,
  "error_message": null
}
```

## Required Correlation IDs

All log-producing code should attach IDs when available:

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

## Request ID

Laravel should create or propagate a `request_id` for HTTP requests.

Webhook ingestion should store the request ID on `webhook_deliveries` or include it in related events/logs.

## Pipeline Correlation

Every pipeline run should have a stable `pipeline_run_id` that appears in:

- pipeline_events
- actor_executions
- structured logs
- llm_calls
- UI links/details

## Laravel Logging

Laravel should log:

- webhook receipt
- webhook validation failure
- webhook dedupe decision
- command creation
- manual retry/cancel actions
- enqueue success/failure
- database errors
- auth/security failures on webhook endpoint

Required context when available:

```text
request_id
webhook_delivery_id
pipeline_run_id
paperless_document_id
trigger_source
```

## Python / Dramatiq Logging

Python workers should log:

- actor queued/started/succeeded/failed/retrying
- actor duration
- lock acquisition/release/coalescing
- embedding gate decisions
- Paperless API calls
- Ollama/LiteLLM calls
- retry scheduling
- recovery scan results

Required context when available:

```text
message_id
actor_name
actor_execution_id
pipeline_run_id
paperless_document_id
queue_name
trigger_source
provider
model
```

Python should use structured logging consistently. `structlog` is a good fit because Archibot already uses it in Python code.

## RabbitMQ Observability

Capture or expose:

```text
queue depth
consumer count
message publish rate
message ack/nack rate
dead-letter count
redelivery count
connection/channel errors
```

Recommended queues to monitor:

```text
archibot.webhook
archibot.io
archibot.llm
archibot.embedding
archibot.blocking
```

## PostgreSQL Observability

Important signals:

- connection failures
- migration failures
- slow queries
- lock waits
- pgvector index build duration/failure
- table growth for pipeline_events / actor_executions / webhook_deliveries

PostgreSQL operational logs are not the same as Archibot pipeline events.

## Metrics

Minimum metrics:

```text
webhook_deliveries_total
webhook_deliveries_failed_total
webhook_deliveries_duplicate_total
pipeline_runs_total
pipeline_runs_failed_total
pipeline_runs_blocked_total
pipeline_runs_retrying_total
actor_executions_total
actor_executions_failed_total
actor_retry_total
paperless_request_duration_ms
llm_request_duration_ms
embedding_build_progress
rabbitmq_queue_depth
pending_webhook_deliveries
blocked_pipeline_runs
poll_runs_total
poll_coalesced_total
```

Metrics can be added through Prometheus-compatible endpoints, Laravel metrics middleware, Python instrumentation, RabbitMQ exporter and PostgreSQL exporter.

## Tracing

Tracing is optional in the first implementation, but the architecture should not block it.

Potential future trace spans:

```text
webhook request
start_or_attach_document_pipeline
fetch_document
correct_ocr
embed_document
classify_document
create_review_suggestion
```

OpenTelemetry is the preferred future-compatible path.

## Redaction and Privacy

Do not log:

- API keys
- auth tokens
- webhook secrets
- full document content
- full OCR text
- full LLM prompts containing document content
- full LLM responses if they include sensitive content
- personally sensitive document data beyond minimal identifiers

Allowed:

- paperless document id
- hashes
- durations
- model/provider names
- high-level error messages
- truncated exception summaries

Use explicit redaction helpers for:

```text
Authorization headers
X-Api-Key headers
webhook secret headers
LLM prompts
OCR/document text
```

## Retention

Recommended retention split:

```text
pipeline_events: durable product/audit history, longer retention
structured logs: operational, shorter retention
metrics: medium retention for trend dashboards
traces: short retention if enabled
```

For local/self-hosted setups, log retention should be configurable.

## Dashboards

Recommended Grafana dashboards:

### Pipeline Health

- pipeline runs by status
- failed/blocked/retrying counts
- average document processing duration
- partial failures

### Webhook Health

- webhook deliveries per minute
- duplicate deliveries
- failed deliveries
- pending/blocked deliveries

### Worker Health

- actor executions by status
- retries by actor
- average actor duration
- worker crash/recovery count

### Broker Health

- RabbitMQ queue depth
- dead-letter messages
- consumer count
- redelivery count

### Provider Health

- Paperless request duration/error rate
- Ollama/LiteLLM request duration/error rate
- LLM calls by provider/model
- rate-limit counts

## Implementation Notes

### Laravel

- Add request ID middleware if not present.
- Configure JSON logs for container/runtime mode.
- Add log context helpers for webhook/pipeline IDs.

### Python

- Configure structlog JSON renderer in container/runtime mode.
- Bind contextvars for pipeline/actor IDs.
- Add actor middleware to log start/success/failure/retry.
- Add HTTP client logging wrappers for Paperless and LLM providers.

### Docker / Deployment

- Keep app logs on stdout/stderr.
- Do not write only to local files inside containers.
- Add optional Loki/Promtail/Alloy services in deployment docs if desired.

## Test Requirements

Minimum tests/checks:

- Laravel webhook logs include request_id and webhook_delivery_id.
- Python actor logs include pipeline_run_id and actor_execution_id.
- LLM logs redact prompt/content.
- Paperless error logs include error type but no token.
- Recovery scan logs summary counts.
- Duplicate webhook/poll coalescing logs include same pipeline_run_id.

## Related ADR

- `docs/decisions/0009-use-structured-centralized-observability.md`
