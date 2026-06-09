# Laravel Worker Jobs

## Status

Retired by clean-install decision. See [ADR-0016: Clean-install Retirement of Worker Jobs](../decisions/0016-clean-install-worker-jobs-retirement.md).

`worker_jobs` was a temporary Laravel control plane for legacy Python subprocess processing. Productive maintenance actions have moved to durable Laravel `commands`, `pipeline_runs`, `pipeline_events`, `pipeline_items`, `actor_executions`, Laravel database queues and fixed Python actor commands.

## Current direction

Do not preserve `worker_jobs` as a route, product concept, backend compatibility layer, or historical-data archive.

The clean-install cleanup has removed:

- `/worker-jobs` routes and route helpers;
- Worker Jobs Svelte pages/navigation;
- `WorkerJobController` and related action wiring;
- `WorkerJob`, `WorkerJobLog`, factories, relationships and migrations where no longer needed for a clean schema;
- `WorkerJobDispatcher`, `RunPythonWorkerJob`, `PythonWorkerCommand`, `WorkerResultIngestor`, `WorkerJobRecovery`, stale-cancelling commands and related tests;
- `archibot jobs ...` CLI compatibility;
- dashboard/errors/stats/health/readiness sections that depend on worker-job state.

Do not add `/legacy-worker-jobs` or `/operations-log/legacy-worker-jobs/{id}`. `/operations-log` reads from durable operation tables only:

- `commands`;
- `pipeline_runs`;
- `pipeline_events`;
- `pipeline_items`;
- `actor_executions`;
- webhook deliveries;
- audit logs.

## Clean install / upgrade stance

Operators will clean runtime state before the relevant install/deployment. No old `worker_jobs` rows need to be migrated, archived, retried, stopped, displayed, or recovered.

Any still-required operation must be rebuilt on the durable command/pipeline/actor model; do not reintroduce the retired worker-job path.

## Reset

Destructive reset remains CLI-only and delegates to Laravel/PostgreSQL:

```bash
archibot reset --yes
```

Equivalent direct Laravel command:

```bash
cd laravel
php artisan archibot:reset --yes
```

Add `--include-config` only when intentionally clearing Laravel app settings, setup state, MCP tokens and legacy config files too.
