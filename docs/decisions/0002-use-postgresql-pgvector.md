# ADR-0002: Use PostgreSQL and pgvector as Durable Source of Truth

## Status

Accepted

## Context

The target event-driven architecture needs shared durable state across Laravel and Python workers. SQLite/sqlite-vec is not a good long-term fit for concurrent workers, durable progress/retry state and vector search in a multi-component pipeline.

## Decision

Use PostgreSQL as the shared source of truth and pgvector for document embeddings.

PostgreSQL stores webhook deliveries, commands, pipeline runs, pipeline items, pipeline events, actor executions, embedding index state, LLM call history and document embeddings.

## Consequences

- Laravel and Python share one durable state model.
- Progress and retry state survive worker restarts and container rebuilds.
- Embedding similarity search moves from sqlite-vec to pgvector.
- Migrations must account for pgvector-specific indexes while keeping local smoke checks practical.
- Runtime state must not be split into separate Laravel/Python status worlds.
