# ADR-0013: Use Absurd as the PostgreSQL-backed Python Queue

## Status

Superseded by [ADR-0015: Use Laravel Database Queues for Event Transport](0015-use-laravel-database-queues-for-event-transport.md). Retained as historical context only.

## Context

Archibot's target event-driven path needs durable Python actor execution for Paperless webhooks, polling reconciliation, embedding builds, document processing, review commits, retry and recovery. PostgreSQL is already the durable source of truth for commands, pipeline runs, pipeline events, progress, retries, audit data and pgvector embeddings.

Keeping a separate broker/runtime adds another deployed service and another state boundary. The Docker-first target is a single Archibot app container plus PostgreSQL/pgvector, with external Paperless and AI-provider services.

## Historical Decision

Use Absurd as the Python queue transport and worker runtime. Absurd stores queue state in PostgreSQL and is started by `python -m app.event_worker start-workers`.

The Absurd PostgreSQL schema is vendored at `laravel/database/sql/absurd.sql` and installed by Laravel migrations before Python workers start. The Python dependency is pinned as `absurd-sdk==0.4.0`; upgrades must keep the vendored SQL and SDK version in sync.

New implementation work must follow ADR-0015 instead: Laravel database queues are the transport, and Laravel queued jobs invoke fixed, allowlisted Python actor commands while durable state remains in PostgreSQL pipeline tables.

## Consequences

- No separate broker service is required in the standard Compose stack.
- `ABSURD_DATABASE_URL` or `DATABASE_URL` configures the queue connection.
- PostgreSQL remains the source of truth for product state; Absurd was execution transport and task state under this superseded decision.
- Laravel-created commands and pipeline runs remained durable even if enqueue failed; recovery scans bridged pending/queued state back into Absurd.
- New code must not follow this superseded Absurd terminology; use ADR-0015 terminology instead.
