# ADR-0013: Use Absurd as the PostgreSQL-backed Python Queue

## Status

Accepted. Supersedes ADR-0001's Dramatiq runtime choice and ADR-0003's RabbitMQ broker choice.

## Context

Archibot's target event-driven path needs durable Python actor execution for Paperless webhooks, polling reconciliation, embedding builds, document processing, review commits, retry and recovery. PostgreSQL is already the durable source of truth for commands, pipeline runs, pipeline events, progress, retries, audit data and pgvector embeddings.

Keeping RabbitMQ/Dramatiq as a separate broker/runtime adds another deployed service and another state boundary. The Docker-first target is a single Archibot app container plus PostgreSQL/pgvector, with external Paperless and AI-provider services.

## Decision

Use Absurd as the Python queue transport and worker runtime. Absurd stores queue state in PostgreSQL and is started by `python -m app.event_worker start-workers`.

The Absurd PostgreSQL schema is vendored at `laravel/database/sql/absurd.sql` and installed by Laravel migrations before Python workers start. The Python dependency is pinned as `absurd-sdk==0.4.0`; upgrades must keep the vendored SQL and SDK version in sync.

## Consequences

- No RabbitMQ service is required in the standard Compose stack.
- `ABSURD_DATABASE_URL` or `DATABASE_URL` configures the queue connection.
- PostgreSQL remains the source of truth for product state; Absurd is execution transport and task state.
- Laravel-created commands and pipeline runs remain durable even if enqueue fails; recovery scans bridge pending/queued state back into Absurd.
- Existing compatibility names may remain temporarily, but new code should use Absurd terminology.
