# Event-driven Migration Phase Status

## Evidence identity

- Status date: 2026-07-18
- Implementation baseline inspected: the repository tree at the commit containing this status file
- Scope: repository-file inspection of queue transport, actor runners, durable models, runtime configuration and migration docs
- Runtime/live-service validation: not performed for this status refresh

This file records current implementation state and remaining product debt. It is not a second architecture plan. Accepted ADRs and current architecture docs are authoritative; implementation plans preserve delivery history only.

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
- CLI/UI parity is implemented: maintenance, reset and review commit delegate to Laravel/PostgreSQL durable seams. Entity decisions are PostgreSQL-owned and the Python/SQLite entity-sync actor is retired.

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
- Supervisor starts `laravel-queue-worker`, `laravel-scheduler`, and `laravel-durable-recovery`; no Python queue or recovery worker exists.
- Scheduled polls skip active or recently completed scheduled poll Commands and dispatch through `RunPythonActorJob::pollReconciliation`.
- Laravel Recovery handles source-linked stale/retryable Actor Executions with bounded attempts, safe cancellation finalization, stale running Commands, Entity Approval sync, and fresh webhook dispatch suppression.
- Confidence-based Python auto-commit is removed under ADR-0018. Only an authorized manual acceptance creates and dispatches a durable `review_commit` Command through Laravel.

## Confirmed transition state

The event-driven cutover is complete in productive code: actor modules are plain functions, Laravel Database Queues are the sole transport, Laravel owns scheduling, Pipeline Start and redispatch, Python owns fenced domain lifecycle, and clean installs create neither legacy SQLite processing state nor the retired queue schema. Existing historical queue-schema objects and `classifier.db` files remain inert for explicit retention or rollback; see the [queue transport removal notes](absurd-removal.md) and [SQLite disposition](sqlite-disposition.md).

Repository CI validates the Python and Laravel suites, frontend gates, clean Docker build, dependency checks, Graphify artifacts, and available image-security scanners. Live PostgreSQL/Paperless deployment exercises remain release evidence rather than incomplete implementation work.

## Remaining product follow-ups

- Complete full Reindex behavior and finite actor/process timeout policy in separately reviewed slices.
- Keep disabled redesign tracks contained until their explicit approval gates pass.
- Exercise deployment-specific upgrade, backup, rollback, scheduler timing and Paperless integration before a stable release.

Use the checks from [`docs/agent/CHECKS.md`](../agent/CHECKS.md) and record current, revision-bound results under [`docs/agent/CONTEXT_AND_EVIDENCE.md`](../agent/CONTEXT_AND_EVIDENCE.md).
