# Repository Governance

Archibot is being migrated to an event-driven architecture using Paperless webhooks, periodic polling reconciliation, PostgreSQL/pgvector, RabbitMQ and Dramatiq.

## Governance goals

- Keep architecture decisions durable in `docs/decisions/`.
- Keep implementation status and shared contracts in committed docs, not hidden chat history.
- Prefer small, reviewable milestones with green tests before moving on.
- Do not extend the legacy Laravel-subprocess/Python-CLI worker path.
- Do not add a permanent legacy/new compatibility mode.

## Source of truth

- Target architecture: `docs/implementation-plan-event-driven-archibot.md`.
- Non-negotiable migration prompt: `docs/prompts/pi-dev-event-driven-migration.md`.
- Architecture details: `docs/architecture/`.
- ADRs: `docs/decisions/`.
- Current phase status: `docs/implementation-notes/event-driven-phase-status.md` when present.

## Commit scope

Use small logical commits by layer where possible:

- `docs:` governance, ADRs and implementation notes
- `infra:` Docker, RabbitMQ, PostgreSQL and deployment config
- `laravel:` Laravel migrations, models, controllers, UI and tests
- `python:` Dramatiq actors, broker, DB/session helpers and worker bootstrap
- `pipeline:` shared pipeline-start, locks, idempotency, progress and retry flows
- `test:` focused tests and smoke checks

## Review rule

Every change must be checked against `docs/governance/review-checklist.md` and the non-negotiable architecture rules in the migration prompt.
