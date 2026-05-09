# ADR-0004: Do Not Add a Long-term Legacy Compatibility Mode

## Status

Accepted

## Context

A permanent dual backend such as `legacy|dramatiq` would make behavior harder to reason about, require duplicate UI/status models and increase the chance that new work extends the old Laravel-subprocess/Python-CLI path.

## Decision

Do not introduce a long-term legacy compatibility mode.

The migration direction is replacement. Temporary scaffolding may exist only as a small, reviewable transition step and must not be designed as a permanent operator-selectable backend.

## Consequences

- New event-driven work must target PostgreSQL/RabbitMQ/Dramatiq contracts.
- Do not add feature flags such as `ARCHIBOT_WORKER_BACKEND=legacy|dramatiq`.
- Remove or retire old worker paths when the replacement path is accepted.
- Documentation and agent guidance must point toward the target architecture, not legacy extension.
