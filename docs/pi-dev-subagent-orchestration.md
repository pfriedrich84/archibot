# Pi.dev Subagent Orchestration for Archibot

This guide's old phase examples are historical. For new work it complements `docs/implementation-plan-security-architecture-hardening.md`; ADR-0017 supersedes Worker Job/Absurd ownership references below.

Use it only for general orchestration discipline, applying the active hardening plan's PR sequence and ownership rules.

## Core Rule

Use one fresh subagent per phase or sub-phase.

The main agent is the orchestrator. It preserves the global architecture direction, reviews phase output, runs or validates tests, commits, and starts the next phase with a fresh context.

A subagent should work on one bounded phase only. Do not let one subagent continue across unrelated phases.

## Why

Archibot crosses several domains:

- Laravel controllers, models, migrations, queues and Inertia/Svelte UI
- Python CLI, worker logic and processing core
- Queue/process reliability
- Recovery and cancellation semantics
- Tests and Docker runtime
- Event-driven migration with commands, Pipeline Runs, Laravel Database Queues and fixed Python actor commands

A single long-running agent will likely lose context, mix layers, over-refactor Python, or skip tests. Fresh subagents reduce that risk.

## Orchestrator Responsibilities

The main agent must:

1. Read `docs/implementation-plan-security-architecture-hardening.md` and its accepted ADRs.
2. Choose the next dependency-ordered milestone/PR slice to execute.
3. Start a fresh subagent for that phase.
4. Give the subagent a narrow scope and relevant files only.
5. Review the resulting diff.
6. Run or validate tests.
7. Commit with a concise Conventional Commits-style subject after current validation passes.
8. Decide whether to continue, split the phase, or stop for review.
9. Keep the repository moving toward the active hardening plan and ADR-0017 ownership model.

The orchestrator must prevent scope drift. If a subagent starts rewriting unrelated Python processing, changing unrelated UI, or jumping ahead to later phases, stop and re-scope.

## Subagent Responsibilities

Each subagent must:

1. Read only the implementation plan plus phase-relevant files.
2. Implement the current phase or sub-phase.
3. Add or update tests.
4. Avoid unrelated refactors.
5. Keep working changes small and reviewable.
6. Return a concise phase summary.

Every subagent summary must include:

```text
Phase / sub-phase completed:
Files changed:
Tests added or updated:
Commands run:
Result:
Remaining risks:
Recommended next phase:
```

## When to Split a Phase

If a phase becomes too large, split it into subagents.

Examples:

```text
Phase 2A: migrations, config and model helpers
Phase 2B: atomic acquisition in RunPythonWorkerJob
Phase 2C: heartbeat and process loop in PythonWorkerCommand
Phase 2D: lease/heartbeat tests
```

```text
Phase 7A: controller and route for job detail
Phase 7B: Svelte detail page
Phase 7C: log pagination and downloads
Phase 7D: tests
```

Use sub-phases when a change crosses more than two layers, touches both PHP and Svelte, or needs multiple test suites.

## Recommended Phase-to-Subagent Mapping

| Plan phase | Recommended subagent split |
|---|---|
| Phase 1: Centralize Worker Job Dispatch | Usually one subagent; split tests if needed |
| Phase 2: Lease and Heartbeat | Split into 2A-2D |
| Phase 3: Recover Lost Jobs | One or two subagents: service plus command/tests |
| Phase 4: Run Recovery Automatically | One subagent |
| Phase 5: Harden Cancel and Force Kill | Split process handling and UI/API if needed |
| Phase 6: Idempotent Result Ingest | Split review suggestions, OCR reviews and partial ingest if needed |
| Phase 7: Worker Job Detail UI | Split backend and frontend |
| Phase 8: Full Worker Controls | Split payload/API and UI |
| Phase 9: Admin Maintenance Page | Split backend and frontend |
| Phase 10: GUI Parity Matrix | One documentation subagent |
| Phase 11: Dashboard and Health | Split dashboard and health checks |
| Phase 12: Document Job-Control Architecture | One documentation subagent |
| Phase 13+: Event-driven migration | Always split by actor/flow |

## Quality Gates Before Commit

Before committing a phase, the orchestrator must check:

- Does the change follow the current phase scope?
- Are tests added or updated?
- Are existing tests still expected to pass?
- Are migrations safe for SQLite and PostgreSQL?
- Are job state transitions explicit?
- Are audit logs and worker logs preserved?
- Does retry remain idempotent?
- Does this avoid expanding `worker_jobs` as permanent architecture?

## Stop Conditions

Stop and ask for human review if:

- A phase requires a major architecture decision not covered by the plan.
- Tests reveal existing inconsistent behavior that needs product clarification.
- The subagent needs to remove working Python behavior.
- The implementation would make `worker_jobs` a permanent competing architecture.
- Data loss or destructive reset behavior is involved without explicit safeguards.

## Initial Orchestrator Prompt

```md
You are the orchestrator for `pfriedrich84/archibot`.

Read:
- `docs/implementation-plan-laravel-job-control.md`
- `docs/pi-dev-subagent-orchestration.md`
- `docs/implementation-plan-event-driven-archibot.md`

Work through the Laravel job-control plan step by step.

Use one fresh subagent per phase or sub-phase. The orchestrator must preserve the global architecture direction and keep each subagent narrowly scoped.

Start with Phase 1: Centralize Worker Job Dispatch.

For Phase 1, start a fresh subagent with this scope:
- Add dispatch tracking migration.
- Add WorkerJob model helpers/casts/fillable fields.
- Create WorkerJobDispatcher.
- Replace direct WorkerJob creation in WorkerJobController, ReviewSuggestionController and EntityApprovalController.
- Add or update tests for dispatch, dedupe, retry and queue behavior.

Do not rewrite the Python processing core.
Do not jump ahead to lease/heartbeat until Phase 1 tests pass.
After Phase 1, produce a summary, run relevant tests, commit with:
`laravel: centralize worker job dispatch`
Then continue to the next phase only if tests pass.
```
