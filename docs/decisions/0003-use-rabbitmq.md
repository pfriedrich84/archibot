# ADR-0003: Use RabbitMQ as Dramatiq Broker

## Status

Accepted

## Context

Archibot's target pipeline needs queue separation, retryable delivery and worker coordination for webhook, I/O, embedding, LLM and maintenance work.

## Decision

Use RabbitMQ as the Dramatiq broker.

RabbitMQ provides the transport for Dramatiq messages. PostgreSQL remains the source of truth for job state, progress, retries and audit data.

Initial queues:

```text
archibot.webhook
archibot.io
archibot.llm
archibot.embedding
archibot.blocking
```

The queue prefix is configurable with `ARCHIBOT_QUEUE_PREFIX`.

## Consequences

- Workers can be scaled and routed by workload type.
- Broker outages must not lose persisted webhook deliveries or commands.
- Recovery scans must requeue safe pending/retryable work from PostgreSQL.
- RabbitMQ depth and dead-letter behavior should be observable.
