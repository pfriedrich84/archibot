# Repository Governance

Archibot is being migrated to an event-driven architecture using Paperless webhooks, periodic polling reconciliation, PostgreSQL/pgvector, RabbitMQ and Dramatiq.

## Governance goals

- Keep architecture decisions durable in `docs/decisions/`.
- Keep implementation status and shared contracts in committed docs, not hidden chat history.
- Prefer small, reviewable milestones with green tests before moving on.
- Do not extend the legacy Laravel-subprocess/Python-CLI worker path.
- Do not add a permanent legacy/new compatibility mode.

## Source of truth

- Agent operating contract: `AGENTS.md`, with modular instructions in `docs/agent/`.
- Target architecture: `docs/implementation-plan-event-driven-archibot.md`.
- Non-negotiable migration prompt: `docs/prompts/pi-dev-event-driven-migration.md`.
- Architecture details: `docs/architecture/`.
- ADRs: `docs/decisions/`.
- Current phase status: `docs/implementation-notes/event-driven-phase-status.md` when present.
- Trust boundaries: `docs/governance/trust-boundaries.md`.
- Release expectations: `docs/governance/release-governance.md`.

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

Changes to CI, GitHub Actions, Dependabot configuration, Docker images, MCP servers, external providers, package registries, secrets, runtime data stores, AI-provider routing, or committed Graphify artifacts must also be checked against `docs/governance/trust-boundaries.md`.

Release-impacting changes must also be checked against `docs/governance/release-governance.md`.

## Documentation topology

- `AGENTS.md` is the canonical entrypoint for coding agents.
- `docs/agent/` contains durable operating rules, checks, memory, anti-patterns, safety, and definition-of-done guidance.
- `docs/decisions/` contains accepted architecture decisions for the event-driven migration.
- `docs/architecture/` contains detailed target contracts and invariants.
- `docs/developer/` contains implementation references for maintainers.
- `docs/user/` contains operator and end-user documentation.
- `docs/governance/` contains collaboration, review, trust-boundary, and release-governance guidance.

Avoid moving or renaming documents unless the target ownership is obvious and the benefit outweighs review noise.
