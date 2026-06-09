# ADR-0012: Worker Jobs as Temporary Laravel Control Plane

## Status

Superseded by [ADR-0016: Clean-install Retirement of Worker Jobs](0016-clean-install-worker-jobs-retirement.md).

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

User-facing operations must not preserve `worker_jobs` as a product route or concept. ADR-0016 supersedes the compatibility-storage approach: the next removal is a clean-install retirement of `worker_jobs`, not a migration of old rows into Operations Log. `/operations-log` must be built on durable command/pipeline/actor state, not worker-job compatibility data.

## Consequences

- Short-term reliability improves without rewriting the Python core first.
- Laravel remains the owner of UI, control actions, audit logs and readiness reporting.
- Python remains the owner of document processing logic.
- `worker_jobs` is hardened but still temporary.
- No permanent product architecture should be built solely on `worker_jobs`.
- User-facing routes and navigation should converge on Operations Log and durable command/pipeline/actor terminology, not Worker Jobs or Legacy Worker Jobs.
- New `worker_jobs` features are no longer allowed; remove remaining worker-job paths after required durable replacements exist.
- Future contributors must avoid creating a third long-lived job-control system.

## References

- [Job-control model](../architecture/job-control-model.md)
- [Event-driven implementation plan](../implementation-plan-event-driven-archibot.md)
- [Laravel job-control implementation plan](../implementation-plan-laravel-job-control.md)
