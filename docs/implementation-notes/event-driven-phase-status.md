# Event-driven Migration Phase Status

## Evidence identity

- Status date: 2026-07-17
- Implementation baseline inspected: the repository tree at the commit containing this status file
- Scope: repository-file inspection of queue transport, actor runners, durable models, runtime configuration and migration docs
- Runtime/live-service validation: not performed for this status refresh

This file records current implementation state and migration debt. It is not a second architecture plan. Active sequencing lives in [`docs/implementation-plan-security-architecture-hardening.md`](../implementation-plan-security-architecture-hardening.md); the event-driven plan supplies subordinate migration detail and accepted ADRs remain authoritative.

## Current target

The active event transport is Laravel database queues invoking fixed, allowlisted Python actor commands. PostgreSQL pipeline tables remain the durable product state.

- [ADR-0015](../decisions/0015-use-laravel-database-queues-for-event-transport.md) supersedes Absurd for new implementation work.
- [ADR-0016](../decisions/0016-clean-install-worker-jobs-retirement.md) retires `worker_jobs` for clean installs.
- [ADR-0017](../decisions/0017-single-durable-orchestration-and-execution-ownership.md) requires Laravel-only transport/Pipeline Start, PostgreSQL-only productive state and Python domain lifecycle ownership.
- [ADR-0018](../decisions/0018-suspend-model-confidence-auto-commit.md) requires immediate auto-commit containment before processing is considered safe.
- Paperless Webhooks remain primary; automatic polling remains 600-second reconciliation/fallback through Laravel-owned Pipeline Start.

## Confirmed implemented foundation

### Durable state and product flows

- PostgreSQL migrations/models exist for webhook deliveries, commands, pipeline runs/events/items, actor executions, embedding readiness, LLM calls, document embeddings, review suggestions and audit-facing state.
- Webhook ingestion validates and persists deliveries, deduplicates input and creates/attaches durable document runs without synchronous OCR/LLM processing.
- Embedding readiness, document pipeline start/attach, retry/recovery state, progress, reprocess metadata and Laravel-triggered review commits use durable PostgreSQL records.
- Laravel UI exposes durable pipeline, webhook, maintenance, review and operations controls with admin boundaries on job-control actions.
- Step 9 completed CLI/UI parity: maintenance, reset and review commit delegate to Laravel/PostgreSQL durable seams. Entity decisions are PostgreSQL-owned and the Python/SQLite entity-sync actor is retired.

### Laravel queued actor transport

The Laravel transport path is implemented:

```text
producer/controller/service
  -> App\Jobs\RunPythonActorJob
  -> Laravel database queue
  -> App\Services\Actors\PythonActorRunner
  -> python -m app.actor_runner <allowlisted command> <durable id>
  -> Python actor implementation
```

Confirmed allowlisted flows:

- embedding index build;
- document pipeline;
- review commit;
- poll reconciliation;
- reindex command, currently implemented as an embedding-index rebuild rather than full reindex parity;
- OCR reindex;
- PostgreSQL-owned entity approval decision application;
- Paperless webhook handling.

Laravel producers and recovery services dispatch small jobs containing one allowlisted actor name and one durable record ID. Python loads command/run/delivery options from PostgreSQL before processing. Actor Executions now carry nullable source links to the originating Command, Pipeline Run, or Webhook Delivery so recovery decisions are source-scoped.

### Retired worker-job model

`worker_jobs` runtime compatibility is removed for clean installs:

- no active `WorkerJob` model, queue job, route, controller or table migration was found in application/runtime source;
- operations history uses commands, pipeline runs/events/items, actor executions, webhook deliveries and audit logs;
- old worker-job state is historical only and must not be reintroduced.

## Confirmed Laravel runtime cutover

- `laravel/routes/console.php` registers a one-minute due-check; `POLL_INTERVAL_SECONDS` still controls the actual reconciliation interval and `0` disables it.
- Supervisor starts `laravel-queue-worker`, `laravel-scheduler`, and `laravel-durable-recovery`; it no longer starts `app.event_worker` or an Absurd recovery bridge.
- Scheduled polls skip active or recently completed scheduled poll Commands and dispatch through `RunPythonActorJob::pollReconciliation`.
- Laravel Recovery handles source-linked stale/retryable Actor Executions with bounded attempts, safe cancellation finalization, stale running Commands, Entity Approval sync, and fresh webhook dispatch suppression.
- Confidence-based Python auto-commit is removed under ADR-0018. Only an authorized manual acceptance creates and dispatches a durable `review_commit` Command through Laravel.

## Confirmed transition debt

Security containment milestone 0 is only partially implemented. Step 0.1 disables Chat/RAG for every user and preserves its stored rows without exposure; [Issue #221](https://github.com/pfriedrich84/archibot/issues/221) is the only redesign/re-enable track. Step 0.2 implements ADR-0018: stale confidence thresholds are forced to zero across Laravel export and Python, and model/judge output remains pending for manual review. Step 0.3 removes Laravel OCR content write-back, restore, retry and auto-write configuration while retaining local snapshots; every OCR exposure and mutation now fails closed against live Paperless document permission without an ArchiBot-admin bypass. Step 0.4 is implemented: both webhook aliases require a non-empty effective secret, enforce constant-time authentication plus request-size and rate limits before persistence, and keep the development bypass fail-closed outside local/development environments.

Absurd is no longer a supervised runtime owner, but cleanup remains:

- `app/absurd_queue.py`, `app/event_worker.py`, and Absurd-decorated compatibility wrappers;
- `absurd-sdk` in Python dependency files and Docker installation;
- `ABSURD_DATABASE_URL`, queue-prefix compatibility, vendored SQL, and the install migration;
- Absurd-specific tests and historical documentation references.

The runtime still needs a clean-install/live-service proof after these changes. Full Reindex behavior, two CLI parity gaps, and finite actor/process timeout policy also remain open.

## Next safe milestones

0. **Security containment**
   - Continue the remaining independent hardening-plan milestone 0 PRs after completed Chat/RAG, confidence auto-commit, local-only permission-scoped OCR containment and webhook ingress hardening: restrict/structure diagnostics (0.5), then harden first-run setup and its pinned Paperless origin (0.6).
   - Do not begin redesign work or claim full milestone-0 containment before the remaining applicable slices land.

1. **Runtime and recovery proof**
   - Map every producer to one `RunPythonActorJob` factory and one allowlisted `app.actor_runner` command.
   - Run focused Laravel/Python suites plus a clean-install Docker smoke with PostgreSQL.
   - Verify scheduled poll due/disabled/overlap behavior, restart recovery, source-linked retries, cancellation, and no supervised Absurd process.
   - Verify a durable record cannot be launched through both transports.
   - Complete full reindex behavior, CLI/UI backend parity, and the missing Laravel recovery cases identified above.

2. **Absurd cleanup**
   - Remove SDK/config/schema/migration/supervisor/code/test remnants in a focused patch after parity.
   - Keep Python processing actors and the fixed Laravel-to-Python command boundary.

3. **Remaining parity and documentation sweep**
   - Run clean-install Docker, queue worker, webhook, polling, restart/recovery and operations UI smoke checks without Absurd.
   - Update remaining active user/developer/operations docs and generated graph artifacts.

## Validation requirements for this milestone

Required repository evidence:

- focused Python actor/auto-commit/source-link tests;
- Laravel schedule, recovery, retry, cancellation, migration and actor-job tests;
- Supervisor regression proof that no Absurd worker/recovery program starts;
- Markdown links, Python/Laravel lint and full relevant CI checks.

A clean-install Docker/PostgreSQL/Paperless smoke remains external runtime evidence. Until it is run, end-to-end scheduler timing, restart behavior and dual-dispatch exclusion remain `INCONCLUSIVE` at live-service level even when repository tests pass.

Use the checks from [`docs/agent/CHECKS.md`](../agent/CHECKS.md) and record current, revision-bound results under [`docs/agent/CONTEXT_AND_EVIDENCE.md`](../agent/CONTEXT_AND_EVIDENCE.md).
