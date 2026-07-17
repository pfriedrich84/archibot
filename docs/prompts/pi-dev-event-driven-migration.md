# pi.dev Prompt: Event-driven ArchiBot Migration

## When to use this prompt

Use this prompt only for work that changes the event-driven migration, durable pipeline, queue transport, webhook/reconciliation coordination, actor execution, recovery, or retirement of superseded runtime paths.

Do not preload this prompt for unrelated product, UI, documentation, dependency, or maintenance tasks. The root [`AGENTS.md`](../../AGENTS.md) remains the canonical agent contract and determines the applicable reading order.

## Mission

Move ArchiBot toward the target in [`docs/implementation-plan-event-driven-archibot.md`](../implementation-plan-event-driven-archibot.md):

```text
Paperless Webhooks / Laravel UI / 600-second reconciliation
  -> durable PostgreSQL command and pipeline state
  -> Laravel database queue transport
  -> fixed, allowlisted Python actor commands
  -> Python document processing and provider integrations
```

Laravel queues are transport only. PostgreSQL pipeline records are the product state. Absurd is superseded by ADR-0015, `worker_jobs` is retired by ADR-0016, and [ADR-0017](../decisions/0017-single-durable-orchestration-and-execution-ownership.md) makes Laravel the sole Pipeline Start/transport owner while Python owns domain lifecycle. ADR-0018 requires auto-commit containment before document processing is considered safe. Active ordering comes from the [hardening plan](../implementation-plan-security-architecture-hardening.md).

## Load context by task

First follow the task-triggered routing in [`AGENTS.md`](../../AGENTS.md). For migration work, add only the relevant sources below:

| Change area | Required context |
| --- | --- |
| Overall target or phase sequencing | [Hardening plan](../implementation-plan-security-architecture-hardening.md), [event-driven detail plan](../implementation-plan-event-driven-archibot.md), and [current phase status](../implementation-notes/event-driven-phase-status.md) |
| Queue transport, actor runner, or superseded runtime cleanup | [ADR-0015](../decisions/0015-use-laravel-database-queues-for-event-transport.md), [ADR-0016](../decisions/0016-clean-install-worker-jobs-retirement.md), [ADR-0017](../decisions/0017-single-durable-orchestration-and-execution-ownership.md), and [job-control model](../architecture/job-control-model.md) |
| Webhooks or polling | [Webhook/polling coordination](../architecture/webhook-polling-coordination.md), [ADR-0005](../decisions/0005-use-webhooks-as-primary-trigger.md), and [ADR-0007](../decisions/0007-keep-periodic-polling-with-webhook-dedupe-locks.md) |
| Embedding startup/readiness | [Embedding gate](../architecture/embedding-readiness-gate.md) and [ADR-0006](../decisions/0006-require-complete-embedding-index-before-document-processing.md) |
| Retry or recovery | [Failure/retry/recovery](../architecture/failure-retry-recovery.md), [retry concept](../architecture/retry-concept.md), and [ADR-0008](../decisions/0008-use-durable-retries-and-recovery-for-pipeline-failures.md) |
| Progress or item state | [Progress tracking](../architecture/progress-tracking.md) and [ADR-0010](../decisions/0010-use-durable-progress-tracking.md) |
| Logging or audit | [Observability](../architecture/observability-logging.md) and [ADR-0009](../decisions/0009-use-structured-centralized-observability.md) |
| Job controls or reprocess | [Authorization](../architecture/authorization-job-control.md), [reprocess triggers](../architecture/reprocess-triggers.md), [ADR-0011](../decisions/0011-require-admin-authorization-for-job-control.md), and [ADR-0019](../decisions/0019-separate-review-decisions-from-admin-job-control.md) |

Read focused sections first. Expand only for unresolved contracts, warnings, failures, or behavior touched by the task.

## Non-negotiable direction

- Paperless Webhooks are primary; polling remains automatic every 600 seconds as reconciliation/fallback.
- Webhooks and polling reach the same Laravel-owned Pipeline Start, dedupe and coalescing logic; Python must not gain new start callers.
- No document processing starts before the embedding index is complete.
- PostgreSQL stores durable state, progress, retries, recovery and audit evidence.
- Laravel database queues dispatch jobs carrying only an allowlisted actor name and one stable durable ID; actor options are loaded from PostgreSQL.
- Laravel jobs invoke fixed commands exposed by `python -m app.actor_runner`; arbitrary command strings are forbidden.
- Actor work is idempotent, retry-safe and must not double-count progress or duplicate outputs.
- Long-running actors require explicit timeout, heartbeat, cooperative cancellation and recovery behavior; unbounded jobs are migration debt.
- Laravel owns authorization, UI, command creation, Pipeline Start, dispatch and transport recovery; Python owns processing and domain lifecycle/retry behavior.
- Only admins control jobs. Non-admin review actions still require Paperless document rights.
- Preserve manual review, whitelists, storage-path safety and local-only OCR correction. ADR-0018 requires `auto_commit_confidence` to be disabled; do not run document processing before containment milestone 0.2 lands.
- Extend the existing Laravel operations UI instead of creating another console.
- Do not reintroduce `worker_jobs` or add new behavior to the superseded Absurd transport.

## Working method

1. Confirm repository, branch, `HEAD`, patch state, scope and approval boundaries.
2. Read only the routed context for the affected area.
3. Inspect current code and tests before trusting phase notes or historical plans.
4. Write a short patch plan when work crosses Laravel, Python, schema, runtime, or documentation boundaries.
5. Define shared IDs, statuses, payloads and ownership before parallel work.
6. Keep each patch focused; do not mix transport cleanup, feature work, dependency churn and broad refactors.
7. Update ADRs only for new durable decisions. Update phase status only for evidence-backed implementation milestones or newly confirmed debt.
8. Run targeted checks while developing and the final relevant checks after the last material patch.

For delegation, handoff, evidence and interruption recovery, use:

- [`docs/governance/agent-workflow.md`](../governance/agent-workflow.md)
- [`docs/agent/CONTEXT_AND_EVIDENCE.md`](../agent/CONTEXT_AND_EVIDENCE.md)

Do not restate their full contracts in task prompts or committed phase notes.

## Current migration priority

Use the implementation plan and phase status for current detail. Unless the task explicitly narrows scope, prefer this order:

1. Prove Laravel actor-job parity for every producer, CLI-overlap action and recovery state, including actual full-reindex behavior.
2. Provide automatic 600-second reconciliation through the Laravel-owned runtime path.
3. Prevent dual dispatch and make Laravel queues the exclusive transport.
4. Remove Absurd dependencies, schema, configuration, workers, recovery bridge and obsolete tests.
5. Validate clean-install Docker runtime and end-to-end recovery without Absurd or `worker_jobs`.
6. Remove or mark remaining stale transport documentation.

## Validation and completion

Use [`docs/agent/CHECKS.md`](../agent/CHECKS.md) to select checks. A completion claim must satisfy [`docs/agent/DEFINITION_OF_DONE.md`](../agent/DEFINITION_OF_DONE.md) and the result-state/freshness rules in [`docs/agent/CONTEXT_AND_EVIDENCE.md`](../agent/CONTEXT_AND_EVIDENCE.md).

Final handoff must remain compact and identify:

- changed files and purpose;
- current validation states, commands, counts, warnings and evidence freshness;
- skipped or incomplete coverage and why;
- architecture, migration, operational or trust-boundary impact;
- unresolved work and the next safe step;
- commit and push state.
