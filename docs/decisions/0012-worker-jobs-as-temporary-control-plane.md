# ADR-0012: Worker Jobs as Temporary Laravel Control Plane

## Status

Superseded by [ADR-0016: Clean-install Retirement of Worker Jobs](0016-clean-install-worker-jobs-retirement.md).

This record documents a historical stabilization decision. It is not an active implementation instruction.

## Historical context

At the time this decision was accepted, ArchiBot had more than one job-control model. The Python CLI/core performed processing while Laravel needed reliable admin control, audit, status, retry, cancellation and recovery.

The temporary flow was:

```text
Laravel UI
-> WorkerJobDispatcher
-> worker_jobs
-> RunPythonWorkerJob
-> Python CLI/Core
```

The intent was to reduce immediate product risk without making `worker_jobs` the permanent architecture.

## Historical decision

The project temporarily allowed `worker_jobs` to centralize dispatch, dedupe, leases, heartbeats, recovery, cancellation, retry, progress, logs and admin UI controls while the durable event-driven replacement was being built.

New permanent architecture was not to be built solely on that table. The planned destination was durable commands, pipeline runs/events/items, actor executions, PostgreSQL state and queue-backed Python processing.

## Superseding outcome

The temporary model has now been retired for clean installs under ADR-0016. The active control flow is:

```text
Laravel UI / Webhook / Reconciliation
-> commands and/or pipeline_runs
-> pipeline_events / actor_executions
-> Laravel database queue
-> fixed, allowlisted Python actor command
-> PostgreSQL / pgvector
```

There is no active `worker_jobs` model, table migration, route, queue job, UI, recovery path or compatibility/archive backend. `/operations-log` uses durable command, pipeline, actor, webhook and audit state.

Future work must not:

- reintroduce `worker_jobs` or old Worker Job routes;
- preserve old rows through a compatibility layer;
- route GUI controls or CLI-overlap actions through the retired backend;
- treat the archived stabilization plan as current work.

## Consequences

- Historical worker-job behavior remains available through this ADR and Git history for archaeology.
- Current implementation and documentation use durable command/pipeline/actor terminology.
- Remaining migration work concerns Laravel queue exclusivity, recovery/scheduling parity and Absurd removal, not worker-job hardening.

## References

- [ADR-0016: Clean-install Retirement of Worker Jobs](0016-clean-install-worker-jobs-retirement.md)
- [ADR-0015: Use Laravel Database Queues for Event Transport](0015-use-laravel-database-queues-for-event-transport.md)
- [Current job-control model](../architecture/job-control-model.md)
- [Event-driven implementation plan](../implementation-plan-event-driven-archibot.md)
- [Archived Laravel job-control implementation plan](../implementation-plan-laravel-job-control.md)
