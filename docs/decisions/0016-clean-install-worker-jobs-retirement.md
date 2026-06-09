# ADR-0016: Clean-install Retirement of Worker Jobs

## Status

Accepted

## Context

The event-driven migration moved productive GUI maintenance actions to durable Laravel `commands`, `pipeline_runs`, `pipeline_events`, `actor_executions`, Laravel database queues and fixed Python actor commands. The remaining `worker_jobs` model was previously treated as temporary compatibility storage for old rows and legacy UI detail pages.

The maintainer has decided that ArchiBot does not need to preserve legacy worker-job data across the next install/deployment boundary. Deployments may clean runtime state before install. Keeping database/backend compatibility for historical `worker_jobs` rows would prolong a parallel control model and conflict with ADR-0004's replacement direction.

## Decision

Retire `worker_jobs` as a clean-install removal instead of preserving legacy data compatibility.

Do not keep or introduce any user-facing route for old worker rows, including:

- `/worker-jobs`
- `/legacy-worker-jobs`
- `/operations-log/legacy-worker-jobs/{id}`

Do not keep backend compatibility solely to read, retry, stop, recover, or display old `worker_jobs` rows after the clean-state removal. The replacement operations model is:

- `commands`
- `pipeline_runs`
- `pipeline_events`
- `pipeline_items`
- `actor_executions`
- webhook deliveries
- audit logs
- Laravel queued actor jobs invoking fixed Python actor commands

`/operations-log` may be introduced as a durable operations history surface, but it must be built from the durable event-driven tables above, not from `worker_jobs` compatibility data.

Operator-facing CLI actions that overlap GUI actions must use the same durable backend. In particular, `archibot reindex-ocr [--force]` must create/dispatch the durable Laravel `reindex_ocr` command path; do not keep a direct OCR operator mode as an alternate backend.

## Consequences

- The next implementation can remove `worker_jobs` routes, controllers, pages, models, migrations, factories, recovery commands, queue jobs, result ingestion, health/readiness checks, docs and tests rather than preserving them behind renamed legacy routes.
- Fresh installs or instructed upgrades must start from a clean runtime database/state where old worker-job rows do not need migration.
- Any still-required operation must be rebuilt on durable command/pipeline/actor tables before the worker-job path is removed.
- Documentation should describe `worker_jobs` only as retired historical architecture, not an active compatibility layer.

## References

- [ADR-0004: Do Not Add a Long-term Legacy Compatibility Mode](0004-no-legacy-compatibility-mode.md)
- [ADR-0012: Worker Jobs as Temporary Laravel Control Plane](0012-worker-jobs-as-temporary-control-plane.md)
- [ADR-0015: Use Laravel Database Queues for Event Transport](0015-use-laravel-database-queues-for-event-transport.md)
- [Job-control model](../architecture/job-control-model.md)
