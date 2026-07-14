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

## Context and evidence management

Follow [`../agent/CONTEXT_AND_EVIDENCE.md`](../agent/CONTEXT_AND_EVIDENCE.md) as the canonical contract.

- Keep active context limited to objective, scope, identity, decisions, findings, changed files, current evidence states and the next safe action.
- For long, delegated, or interruption-prone work, maintain a revision-bound evidence index and a verified recovery checkpoint in safe local non-committed storage.
- Preserve lasting decisions, shared interfaces and phase status in canonical repository docs; do not commit raw logs, transcripts or task-local scratch state.
- Before compaction or handoff, verify repository/patch identity and evidence freshness. On resume, inspect status/diff and mark affected prior evidence `STALE` before continuing.

## Subagent coordination

Subagents may be used for independent tracks such as docs, infrastructure, Laravel schema/API, Python actors, progress/retry helpers, UI/actions and tests.

Rules:

- Do not rely on hidden chat context for essential decisions.
- Write durable notes to `docs/implementation-notes/` when contracts or phase status change.
- Avoid conflicting edits to the same files.
- Define interfaces before multiple agents implement against them.
- Keep subagent outputs concise and evidence-backed.
- Require each subagent to return assigned scope, revision/patch identity, inspected files or claims, commands and result states, complete findings, omissions, residual risks and safe artifact paths.
- Treat timeout, truncation, missing output or incomplete delegated coverage as `INCONCLUSIVE`, not approval.
- Before final completion, reconcile every delegated scope and disposition the complete finding set.

## Test gate

After every logical milestone:

1. Run targeted tests for touched layers.
2. Run broader checks when practical; prefer `scripts/ci-local.sh --fast` before handoff and `scripts/ci-local.sh --full` for release, Docker, workflow, or dependency-sensitive changes.
3. Install `scripts/install-git-hooks.sh` in local clones where possible so the pre-push gate catches formatting, lint, test, and build regressions before GitHub CI.
4. Record identity, exit code, semantic outcome, counts, warnings and freshness using the canonical evidence states; zero exit alone does not establish `PASS`.
5. Document any check that cannot be run locally and the closest smoke check used; classify missing required evidence as `INCONCLUSIVE`.
6. Mark affected results `STALE` after later material edits and rerun them before relying on them.
7. Do not continue feature work while failures from the current milestone are known and unfixed.
