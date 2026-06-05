# ADR-0015: Use Laravel Database Queues for Event Transport

## Status

Accepted. Supersedes [ADR-0013: Use Absurd as the PostgreSQL-backed Python Queue](0013-use-absurd-postgresql-queue.md) for new implementation work.

## Context

ArchiBot's event-driven migration needs durable execution for Paperless webhooks, embedding builds, document pipelines, polling reconciliation, reindexing, retries, recovery and review commits.

The previous target used Absurd as a PostgreSQL-backed Python queue. In practice, the Absurd path is not working reliably enough for the project, while ArchiBot already depends on Laravel, PostgreSQL and Laravel's database queue worker for the operations console and temporary job-control layer.

The project goal remains a smaller and more reliable dependency footprint without introducing a separate broker service or a second product state model.

## Decision

Use Laravel database queues as the event-driven transport for new pipeline execution.

Laravel queued jobs must invoke fixed, allowlisted Python actor commands. They must not execute arbitrary Python command strings or turn `worker_jobs` into the permanent architecture.

PostgreSQL pipeline tables remain the durable source of truth for product state:

- `commands`
- `pipeline_runs`
- `pipeline_events`
- `actor_executions`
- `pipeline_items`
- webhook deliveries, review suggestions, embedding state and document embeddings

Laravel queues are transport only. Python remains the owner of document processing, Paperless and AI-provider calls, embeddings, OCR correction, classification, review commit execution and maintenance logic.

The migration order is:

1. Document and ADR pivot.
2. Add a fixed Python actor-runner command contract.
3. Add a Laravel queued actor wrapper.
4. Migrate embedding builds first.
5. Migrate document pipeline execution next.
6. Migrate webhook handling, reconciliation/recovery, review commit and remaining event-driven flows.
7. Remove Absurd dependency, schema, supervisor programs and environment documentation after parity is tested.

## Consequences

What gets easier:

- Standard runtime uses Laravel, PostgreSQL and one Laravel queue worker path.
- Absurd SDK and vendored Absurd SQL can be removed after migration.
- Operators debug queue transport through Laravel's existing queue tables and worker process.
- The Docker-first dependency footprint is smaller.

What gets harder:

- Laravel queued jobs must carefully supervise fixed Python actor commands.
- Long-running OCR, embedding and LLM work needs explicit timeout, heartbeat, cancellation and retry behavior.
- Implementation must avoid recreating the old broad Laravel-subprocess `worker_jobs` model.

Rules for implementation:

- Durable state remains in PostgreSQL pipeline tables, not Laravel queue payloads.
- Laravel queue job payloads must be small references such as command IDs, pipeline run IDs, webhook delivery IDs or review suggestion IDs.
- Python actor commands must be fixed and allowlisted.
- `worker_jobs` remains temporary legacy/stabilization state and must not receive new permanent pipeline functionality.
- Each migration step must update documentation and pass focused tests before the next flow is migrated.
