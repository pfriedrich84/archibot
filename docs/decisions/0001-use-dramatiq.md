# ADR-0001: Use Dramatiq for Python Pipeline Execution

## Status

Accepted

## Context

Archibot is moving from a Laravel-subprocess/Python-CLI worker path toward an event-driven pipeline. Document processing, embedding, classification, retry and recovery need idempotent units of work with explicit queues and durable execution tracking.

## Decision

Use Dramatiq actors for Python pipeline execution.

Dramatiq actors will process webhook, document, embedding, classification, review and maintenance work. Actors must persist execution state in PostgreSQL and emit pipeline events; RabbitMQ/Dramatiq is execution transport, not the only source of job state.

## Consequences

- Pipeline steps become separately retryable and observable.
- Actors must be idempotent and safe to re-run.
- Laravel should create durable commands/runs and enqueue work rather than launching Python subprocess workers.
- The legacy Laravel-subprocess/Python-CLI worker path must not be extended for new pipeline behavior.
