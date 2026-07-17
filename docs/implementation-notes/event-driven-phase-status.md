# Event-driven Migration Phase Status

## Evidence identity

- Status date: 2026-07-17
- Implementation baseline inspected: the repository tree at the commit containing this status file
- Scope: repository-file inspection of queue transport, actor runners, durable models, runtime configuration and migration docs
- Runtime/live-service validation: not performed for this status refresh

This file records current implementation state and migration debt. It is not a second architecture plan. Target sequencing lives in [`docs/implementation-plan-event-driven-archibot.md`](../implementation-plan-event-driven-archibot.md); accepted ADRs remain authoritative for their decisions.

## Current target

The active event transport is Laravel database queues invoking fixed, allowlisted Python actor commands. PostgreSQL pipeline tables remain the durable product state.

- [ADR-0015](../decisions/0015-use-laravel-database-queues-for-event-transport.md) supersedes Absurd for new implementation work.
- [ADR-0016](../decisions/0016-clean-install-worker-jobs-retirement.md) retires `worker_jobs` for clean installs.
- Paperless Webhooks remain primary; automatic polling remains 600-second reconciliation/fallback through the same pipeline-start logic.

## Confirmed implemented foundation

### Durable state and product flows

- PostgreSQL migrations/models exist for webhook deliveries, commands, pipeline runs/events/items, actor executions, embedding readiness, LLM calls, document embeddings, review suggestions and audit-facing state.
- Webhook ingestion validates and persists deliveries, deduplicates input and creates/attaches durable document runs without synchronous OCR/LLM processing.
- Embedding readiness, document pipeline start/attach, retry/recovery state, progress, reprocess metadata and Laravel-triggered review commits use durable PostgreSQL records.
- Laravel UI exposes durable pipeline, webhook, maintenance, review and operations controls with admin boundaries on job-control actions.
- Several maintenance/reset CLI actions delegate to Laravel, but CLI/UI parity is incomplete: `archibot commit-review` still uses the legacy SQLite suggestion path, and contract-mode entity sync still uses Python-owned state.

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
- entity approval sync;
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
- Python auto-commit creates a durable pending `review_commit` Command. Laravel Recovery, not an Absurd `.send(...)` call, owns dispatch.

## Confirmed transition debt

Absurd is no longer a supervised runtime owner, but cleanup remains:

- `app/absurd_queue.py`, `app/event_worker.py`, and Absurd-decorated compatibility wrappers;
- `absurd-sdk` in Python dependency files and Docker installation;
- `ABSURD_DATABASE_URL`, queue-prefix compatibility, vendored SQL, and the install migration;
- Absurd-specific tests and historical documentation references.

The runtime still needs a clean-install/live-service proof after these changes. Full Reindex behavior, two CLI parity gaps, and finite actor/process timeout policy also remain open.

## Next safe milestones

1. **Runtime and recovery proof**
   - Run focused Laravel/Python suites plus a clean-install Docker smoke with PostgreSQL.
   - Verify scheduled poll due/disabled/overlap behavior, restart recovery, source-linked retries, cancellation, and no supervised Absurd process.
   - Verify a durable record cannot be launched through both transports.

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
