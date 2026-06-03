# Agent Workflow

## Before coding

1. Read `AGENTS.md` and `docs/prompts/pi-dev-event-driven-migration.md`.
2. Read the relevant architecture docs and ADRs.
3. Update shared contracts or phase notes before splitting work across subagents.
4. Identify file ownership to avoid overlapping edits.

## Shared contracts first

Before implementing parallel tracks, document the agreed contracts for:

- database tables and status values
- trigger sources and event names
- queue names and actor names
- pipeline/run/item identifiers
- Laravel API boundaries
- Python helper names and idempotency keys

## Subagent coordination

Subagents may be used for independent tracks such as docs, infrastructure, Laravel schema/API, Python actors, progress/retry helpers, UI/actions and tests.

Rules:

- Do not rely on hidden chat context for essential decisions.
- Write durable notes to `docs/implementation-notes/` when contracts or phase status change.
- Avoid conflicting edits to the same files.
- Define interfaces before multiple agents implement against them.
- Keep subagent outputs concise and evidence-backed.

## Test gate

After every logical milestone:

1. Run targeted tests for touched layers.
2. Run broader checks when practical; prefer `scripts/ci-local.sh --fast` before handoff and `scripts/ci-local.sh --full` for release, Docker, workflow, or dependency-sensitive changes.
3. Install `scripts/install-git-hooks.sh` in local clones where possible so the pre-push gate catches formatting, lint, test, and build regressions before GitHub CI.
4. Document any check that cannot be run locally and the closest smoke check used.
5. Do not continue feature work while failures from the current milestone are known and unfixed.
