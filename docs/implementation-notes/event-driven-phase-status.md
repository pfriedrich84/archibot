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

Laravel producers and recovery services dispatch small jobs containing one allowlisted actor name and one durable record ID. Python loads command/run/delivery options from PostgreSQL before processing.

### Retired worker-job model

`worker_jobs` runtime compatibility is removed for clean installs:

- no active `WorkerJob` model, queue job, route, controller or table migration was found in application/runtime source;
- operations history uses commands, pipeline runs/events/items, actor executions, webhook deliveries and audit logs;
- old worker-job state is historical only and must not be reintroduced.

## Confirmed transition debt

The exclusive Laravel transport cutover is **not complete**. Absurd remains executable in the runtime and dependency graph:

- `app/absurd_queue.py` and Absurd-decorated actor wrappers;
- `app/event_worker.py` worker and recovery commands;
- `absurd-sdk` in Python dependency files and Docker installation;
- `ABSURD_DATABASE_URL` configuration and examples;
- vendored `laravel/database/sql/absurd.sql` and its install migration;
- `event-queue-workers` and `event-recovery-bridge` supervisor programs;
- Absurd-specific tests and documentation references.

Because Laravel recovery and Absurd recovery paths coexist, dual-dispatch behavior has not been ruled out by this documentation-only review. Do not describe the runtime as Laravel-only until parity, exclusive ownership and cleanup are validated.

Automatic 600-second polling has no Laravel scheduler definition in `laravel/routes/console.php` or application scheduling code, and Supervisor does not run Laravel `schedule:work`. The existing Absurd/event-worker path owns periodic behavior. A Laravel-owned schedule must be implemented and validated before the Absurd scheduler is removed.

Laravel-native recovery is also incomplete. Current inspection did not find recovery handling for `sync_entity_approval`, stale running actor executions, `cancel_requested` finalization, or every failed/retrying actor path. Those cases must reach parity before Laravel recovery becomes the exclusive owner.

## Next safe milestones

1. **Transport inventory and parity proof**
   - Map every producer to one `RunPythonActorJob` factory and one allowlisted `app.actor_runner` command.
   - Add or confirm focused dispatch, runner, state-transition and recovery tests for all eight flows.
   - Complete full reindex behavior, CLI/UI backend parity, and the missing Laravel recovery cases identified above.

2. **Laravel-owned reconciliation schedule**
   - Add a single automatic 600-second Laravel schedule/command path.
   - Prove it creates durable poll-reconciliation work and uses the same document start/attach service as webhooks.

3. **Exclusive transport cutover**
   - Disable/remove Absurd dispatch and recovery ownership.
   - Prove pending/retrying work is redispatched only by Laravel-native recovery.
   - Verify no durable record can be launched through both transports.

4. **Absurd cleanup**
   - Remove SDK/config/schema/migration/supervisor/code/test remnants in a focused patch after parity.
   - Keep Python processing actors and the fixed Laravel-to-Python command boundary.

5. **Runtime proof and documentation sweep**
   - Run clean-install Docker, queue worker, webhook, polling, restart/recovery and operations UI smoke checks without Absurd.
   - Update remaining active user/developer/operations docs and generated graph artifacts.

## Validation state for this status

Historical test totals were removed because they were not revision-bound to the current implementation patch and could not support a current `PASS` claim.

For this documentation refresh:

- repository transport inspection: `PASS_WITH_WARNINGS` — Laravel actor transport and retired `worker_jobs` state were confirmed; live runtime behavior was not exercised;
- exclusive Laravel cutover: `FAIL` — executable Absurd runtime paths remain;
- automatic Laravel-owned 600-second reconciliation: `FAIL` — static inspection confirms no Laravel schedule or `schedule:work` runtime, while periodic behavior remains on the superseded event-worker path.

Before an implementation milestone is marked complete, run the relevant checks from [`docs/agent/CHECKS.md`](../agent/CHECKS.md) and record current, revision-bound evidence under [`docs/agent/CONTEXT_AND_EVIDENCE.md`](../agent/CONTEXT_AND_EVIDENCE.md).
