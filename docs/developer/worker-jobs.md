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

When Absurd is configured, Absurd actors and the event recovery bridge may also run, but they are separate from this temporary `worker_jobs` path.

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
| `EMBEDDING_DOCUMENT_TIMEOUT_SECONDS` | `180` | Per-document timeout for embedding reindex work. A timeout records `document_failed` and continues with the next document. |
| `EMBEDDING_MAX_CHARS` | `6000` | Maximum title/content characters sent to the embedding model for each document. Larger documents are truncated for embedding/FTS indexing. |

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

## Poll progress and phase batching

`poll` worker jobs emit machine-readable `PROGRESS` lines while they run. The
Laravel worker detail page uses these lines for live phase-local progress such
as `OCR 4/19`, `Embedding 12/19`, `Klassifizierung 3/19`, or `Judge 2/7`.

The poll pipeline is deliberately phase-batched to avoid unnecessary model
swaps:

1. prepare all documents;
2. OCR all documents;
3. embed all documents and collect similar-document context;
4. classify all documents;
5. judge all successful classifications;
6. store suggestions and run review/auto-commit post-processing;
7. finalize/persist embeddings.

Durability is per document inside each phase. If a phase fails at document
`12/19`, the successful results for documents `1..11` remain persisted and the
worker logs identify the failing phase and document.

## Embedding reindex guardrails

`reindex_embed` emits `PROGRESS` before each document starts with `document_started`, `document_id`, `document_title`, `document_index`, `document_total`, and `content_length`. This makes the currently slow document visible in the Laravel worker-job detail page.

Large documents are bounded by `EMBEDDING_MAX_CHARS` before calling the embedding model. Slow embedding calls are bounded by `EMBEDDING_DOCUMENT_TIMEOUT_SECONDS`. If one document fails or times out, the job emits `document_failed`, records the failed document ID, and continues where safe. The final worker result includes `failed` and `failed_document_ids`; Laravel marks the worker job `partially_failed` when any documents failed.

Large reindexes can still take a long time because each document requires an embedding request. Watch the current document progress and Ollama logs before assuming the worker is stuck.

## CLI-only Laravel reset

Destructive reset controls are intentionally not exposed in the Laravel GUI. Operators should use the stable ArchiBot CLI command; it delegates to the Laravel/PostgreSQL reset in the background:

```bash
archibot reset --yes
```

Equivalent direct Laravel command:

```bash
cd laravel
php artisan archibot:reset --yes
```

This clears Laravel/PostgreSQL runtime and job-control tables including `worker_jobs`, `worker_job_logs`, `jobs`, `failed_jobs`, sessions/cache, chat state, webhook deliveries, command/pipeline tables, actor executions, review suggestions, OCR reviews, entity approvals, audit logs, embedding index state, document embeddings, and LLM call history. Add `--include-config` only when intentionally clearing Laravel app settings, setup state, MCP tokens, and legacy config files too.

Do not use this path as new permanent architecture. New durable processing should continue to move toward `commands`, `pipeline_runs`, `pipeline_events`, Absurd, and Absurd.
