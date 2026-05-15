# Laravel Worker Jobs

Laravel `worker_jobs` are the temporary control plane for running Python processing from the Archibot UI while the event-driven `commands` / `pipeline_runs` architecture is being completed.

## How jobs run

1. UI or webhook code dispatches work through `WorkerJobDispatcher`.
2. A `worker_jobs` row is created or an active duplicate is reused.
3. `RunPythonWorkerJob` is pushed to the Laravel queue.
4. The queue worker acquires a lease, starts the Python CLI subprocess, and writes heartbeats while it runs.
5. Results are ingested and the job reaches a terminal status.

Active jobs use `dispatch_key`, `dispatch_attempts`, `dispatched_at`, `worker_id`, `lease_expires_at`, and `heartbeat_at` for dedupe, dispatch tracking, leasing, and recovery.

## Required processes

The container entrypoint starts these Laravel-side processes:

- `php artisan queue:work` to execute queued worker jobs.
- A background loop running `php artisan worker-jobs:recover --no-interaction` to recover lost queued, running, or cancelling jobs.
- The Laravel web server for the UI/API.

When RabbitMQ is configured, Dramatiq actors and the event recovery bridge may also run, but they are separate from this temporary `worker_jobs` path.

## Environment variables

| Variable | Default | Purpose |
| --- | --- | --- |
| `QUEUE_WORKER_TIMEOUT` | `900` | Timeout for the Laravel queue worker process. |
| `ARCHIBOT_WORKER_RECOVERY_INTERVAL_SECONDS` | `30` | Sleep interval for the automatic worker-job recovery loop. |
| `ARCHIBOT_PENDING_REDISPATCH_SECONDS` | `900` | Base age after which queued jobs are considered stale for conservative redispatch. |
| `ARCHIBOT_STALE_RUNNING_MINUTES` | `10` | Heartbeat age after which running jobs are recovered. |
| `ARCHIBOT_WORKER_MAX_DISPATCH_ATTEMPTS` | `3` | Maximum dispatch attempts before stale queued/running jobs are failed. |
| `ARCHIBOT_WORKER_LEASE_SECONDS` | `300` | Lease duration while a queue worker owns a job. |
| `ARCHIBOT_WORKER_HEARTBEAT_SECONDS` | `15` | Minimum interval between heartbeat writes during Python processing. |
| `ARCHIBOT_STALE_CANCELLING_MINUTES` | `30` | Age after which cancelling jobs are marked cancelled by recovery. |

## Recovery behavior

`worker-jobs:recover` performs three checks:

- Redispatches stale queued jobs where `dispatched_at` is missing or old, using `ARCHIBOT_PENDING_REDISPATCH_SECONDS * max(1, dispatch_attempts)` backoff to avoid redispatch/log spam when queue workers are not consuming.
- Fails queued jobs with `queued_dispatch_attempts_exhausted` and a `worker_job.queued_dispatch_failed` log when `dispatch_attempts` reaches `ARCHIBOT_WORKER_MAX_DISPATCH_ATTEMPTS` before acquisition.
- Requeues stale running jobs when their heartbeat or lease is expired and dispatch attempts remain.
- Fails stale running jobs with `stale_running_timeout` when dispatch attempts are exhausted.
- Marks stale cancelling jobs as cancelled via the existing stale-cancelling cleanup.

Each non-dry-run recovery records lightweight status in `app_settings`:

- `worker_jobs.recovery.last_successful_at`
- `worker_jobs.recovery.last_error`
- `worker_jobs.recovery.last_error_at`

Run recovery manually with:

```bash
cd laravel
php artisan worker-jobs:recover
```

Preview without changing jobs or dispatching queue work:

```bash
php artisan worker-jobs:recover --dry-run
```

## Debugging stuck jobs

1. Check the worker job status, `dispatch_attempts`, `dispatched_at`, `worker_id`, `lease_expires_at`, and `heartbeat_at` in the Laravel database or worker UI.
2. Review worker job logs for `worker_job.redispatched`, `worker_job.queued_dispatch_failed`, `worker_job.stale_running_requeued`, or `worker_job.stale_running_failed` system events.
3. Check `app_settings` for the last successful recovery run and last recovery error.
4. Run `php artisan worker-jobs:recover --dry-run` to see what recovery would change without dispatching new queue work.
5. If queued jobs become stale, `/healthz` reports a `stale_queued_worker_jobs` warning and the dashboard shows a worker queue warning.

### Redispatch loops and queue consumption

A queued job that repeatedly logs `worker_job.redispatched` means recovery found the `worker_jobs` row stale but no Laravel queue worker acquired it before the backoff window expired. Recovery now waits `ARCHIBOT_PENDING_REDISPATCH_SECONDS * max(1, dispatch_attempts)` between redispatches and fails the job with `queued_dispatch_attempts_exhausted` after `ARCHIBOT_WORKER_MAX_DISPATCH_ATTEMPTS` attempts.

To validate queue consumption locally:

```bash
cd laravel
php artisan queue:work --once --verbose
```

Then inspect:

- the Laravel `jobs` table for queued `RunPythonWorkerJob` payloads that are not being consumed;
- `worker_jobs.status` to confirm a healthy queue worker moves the job from `queued` to `running` or a terminal status;
- `worker_jobs.dispatch_attempts` and `worker_jobs.dispatched_at` to see recovery backoff state;
- `worker_jobs.worker_id`, `worker_jobs.lease_expires_at`, and `worker_jobs.heartbeat_at` to confirm the queue worker acquired and heartbeated the job.

If `jobs` rows remain while `queue:work --once --verbose` is running, inspect the queue connection and worker logs before increasing redispatch settings.

If runtime output still says `Pending Seconds: 30` after deploying this code, the running process is not using the intended default. Check for an old container image, run `php artisan config:clear`, remove any environment override such as `ARCHIBOT_PENDING_REDISPATCH_SECONDS=30`, and stop any stale recovery process that was started before the deploy.

## CLI-only Laravel reset

Destructive reset controls are intentionally not exposed in the Laravel GUI. Operators can reset Laravel operational and job-control state from a shell only:

```bash
cd laravel
php artisan archibot:reset --yes
```

This clears Laravel runtime/job-control tables including `worker_jobs`, `worker_job_logs`, `jobs`, `failed_jobs`, webhook deliveries, command/pipeline tables, actor executions, review suggestions, OCR reviews, and entity approvals. Add `--include-config` only when intentionally clearing Laravel app settings and setup state too.

Do not use this path as new permanent architecture. New durable processing should continue to move toward `commands`, `pipeline_runs`, `pipeline_events`, RabbitMQ, and Dramatiq.
