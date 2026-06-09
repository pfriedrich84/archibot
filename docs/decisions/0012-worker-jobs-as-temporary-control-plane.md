# ADR-0012: Worker Jobs as Temporary Laravel Control Plane

## Status

Accepted

## Context

Archibot currently has more than one job-control model. The Python CLI/core is functional and already performs useful processing, while Laravel is the product surface for admin control, audit logs, readiness and user-visible status.

The current hardened model is:

```text
Laravel UI
-> WorkerJobDispatcher
-> worker_jobs
-> RunPythonWorkerJob
-> Python CLI/Core
```

This model centralizes dispatch, dedupe, audit logging, leases, heartbeats, recovery and UI controls around `worker_jobs`. It is intentionally a stabilization layer, not the final event-driven architecture.

The target architecture remains:

```text
Laravel UI / Webhook / Scheduler
-> commands
-> pipeline_runs
-> pipeline_events
-> Laravel database queue
-> fixed Python actor commands
-> PostgreSQL / pgvector
```

## Decision

Use `worker_jobs` as a temporary Laravel control plane while the legacy subprocess path is being replaced.

`worker_jobs` may be hardened for reliability and operator visibility, including dispatch centralization, dedupe, leases, heartbeats, recovery, cancellation, retry, progress, logs and admin UI controls. These changes are allowed because they reduce product risk without rewriting the Python processing core first.

The long-term durable control plane remains `commands`, `pipeline_runs`, `pipeline_events`, `pipeline_items`, `actor_executions`, Laravel queued actor jobs and fixed Python actor commands. New durable pipeline functionality should target those models and actor commands, not only `worker_jobs`. Review suggestion commit requests are migrated to durable `review_commit` commands and the review commit actor; new review commits must not create `worker_jobs`.

User-facing operations must not preserve `worker_jobs` as a product route or concept. The follow-up route migration is to replace `/worker-jobs` with a unified `/operations-log` surface. Do not introduce `/legacy-worker-jobs` or `/operations-log/legacy-worker-jobs/{id}` as transitional product routes; any still-needed old worker-row data should be normalized into Operations Log details while the backend table remains temporary compatibility storage.

## Consequences

- Short-term reliability improves without rewriting the Python core first.
- Laravel remains the owner of UI, control actions, audit logs and readiness reporting.
- Python remains the owner of document processing logic.
- `worker_jobs` is hardened but still temporary.
- No permanent product architecture should be built solely on `worker_jobs`.
- User-facing routes and navigation should converge on Operations Log and durable command/pipeline/actor terminology, not Worker Jobs or Legacy Worker Jobs.
- Each new `worker_jobs` feature must have a migration path to `commands`, `pipeline_runs` and `pipeline_events`.
- Future contributors must avoid creating a third long-lived job-control system.

## References

- [Job-control model](../architecture/job-control-model.md)
- [Event-driven implementation plan](../implementation-plan-event-driven-archibot.md)
- [Laravel job-control implementation plan](../implementation-plan-laravel-job-control.md)
