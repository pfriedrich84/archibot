# Archibot Implementation Plan: Laravel Job Control & Product Reliability

> **Historical plan — do not execute:** Worker Jobs and Absurd targets in this document are superseded by ADR-0016, ADR-0017 and [`implementation-plan-security-architecture-hardening.md`](implementation-plan-security-architecture-hardening.md). The file remains only as migration history and current-code provenance.

## Historical purpose

This plan was the step-by-step working document for the earlier Laravel job-control migration. It must not be used as current implementation instruction.

Archibot's Python processing core was already useful before Laravel was introduced. The current product risk is the Laravel-side control plane: job creation, status tracking, recovery, retries, cancellation, UI parity and operational reliability.

Short-term goal:

```text
Laravel UI -> WorkerJobDispatcher -> worker_jobs -> RunPythonWorkerJob -> Python CLI/Core
```

Long-term goal:

```text
Laravel UI / Webhook / Scheduler
-> commands
-> pipeline_runs
-> pipeline_events
-> Absurd/PostgreSQL
-> Absurd actors
-> PostgreSQL / pgvector
```

There must not be three competing job-control systems indefinitely. `worker_jobs` is a temporary stabilization layer. The durable target remains `commands`, `pipeline_runs` and `pipeline_events`.

## Read First

Before changing code, inspect:

```text
app/cli.py
app/worker.py
app/api_data.py
laravel/routes/web.php
laravel/app/Models/WorkerJob.php
laravel/app/Models/WorkerJobLog.php
laravel/app/Models/AuditLog.php
laravel/app/Models/Command.php
laravel/app/Models/PipelineRun.php
laravel/app/Jobs/RunPythonWorkerJob.php
laravel/app/Services/Workers/PythonWorkerCommand.php
laravel/app/Services/Workers/StaleWorkerJobCanceller.php
laravel/app/Services/Workers/WorkerResultIngestor.php
laravel/app/Http/Controllers/Workers/WorkerJobController.php
laravel/app/Http/Controllers/ReviewSuggestionController.php
laravel/app/Http/Controllers/EntityApprovalController.php
laravel/app/Http/Controllers/MaintenanceCommandController.php
laravel/app/Http/Controllers/PipelineRunController.php
laravel/app/Http/Controllers/EmbeddingIndexController.php
laravel/resources/js/pages/worker/Index.svelte
docs/developer/cli.md
docs/implementation-plan-event-driven-archibot.md
```

## Working Rules

- Use small, reviewable commits.
- Do not rewrite the Python processing core first.
- Do not remove working processing paths before the replacement is tested.
- Do not add a new permanent legacy layer.
- Laravel is the product surface.
- Every reliability change needs tests.
- Every new job path needs audit, logs and status visibility.
- Jobs must not remain invisibly stuck after a crash or restart.
- Retry must not create duplicate suggestions, OCR reviews or commits.

## Definition of Done

The migration is complete when:

- Laravel exposes all old `/app` functionality.
- Every job eventually reaches a terminal status.
- Jobs do not remain invisibly stuck after a container or worker crash.
- Duplicate active jobs are prevented.
- Cancel works reliably.
- Retry is idempotent.
- Reindex blocks normal document processing, but not admin control actions.
- Dashboard shows real system readiness.
- Health checks detect broken queue, worker or recovery processes.
- The job center shows status, logs, progress, payload, result and retry history.
- There is a documented migration path from `worker_jobs` to `pipeline_runs`.

---

## Phase 1: Centralize Worker Job Dispatch

### Goal

All Laravel code that creates worker jobs must go through one service. This gives consistent dedupe, audit logging, payload normalization and dispatch behavior.

### Tasks

1. Create migration `add_dispatch_tracking_to_worker_jobs`.

Add to `worker_jobs`:

```php
$table->string('dispatch_key', 64)->nullable()->after('payload')->index();
$table->unsignedInteger('dispatch_attempts')->default(0)->after('dispatch_key');
$table->timestamp('dispatched_at')->nullable()->after('dispatch_attempts');
```

2. Update `App\Models\WorkerJob`.

Add fillable fields:

```php
'dispatch_key',
'dispatch_attempts',
'dispatched_at',
```

Add cast:

```php
'dispatch_attempts' => 'integer',
'dispatched_at' => 'datetime',
```

Add helpers:

```php
public static function terminalStatuses(): array
{
    return [
        self::STATUS_CANCELLED,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_PARTIALLY_FAILED,
    ];
}

public function isActive(): bool
{
    return in_array($this->status, self::activeStatuses(), true);
}

public function isTerminal(): bool
{
    return in_array($this->status, self::terminalStatuses(), true);
}
```

3. Create `laravel/app/Services/Workers/WorkerJobDispatcher.php`.

Responsibilities:

- Normalize payloads recursively.
- Generate stable dedupe keys.
- Find active duplicate jobs.
- Create `worker_jobs` rows.
- Write `AuditLog` entries.
- Dispatch `RunPythonWorkerJob`.
- Set `dispatched_at` and increment `dispatch_attempts`.

Suggested API:

```php
public function dispatch(
    string $type,
    array $payload = [],
    ?User $user = null,
    ?Request $request = null,
    ?WorkerJob $retryOf = null,
    bool $forceNew = false,
): WorkerJob
```

Active dedupe rule:

```php
WorkerJob::query()
    ->where('dispatch_key', $dispatchKey)
    ->whereIn('status', WorkerJob::activeStatuses())
    ->first();
```

Only active jobs are deduplicated. Terminal jobs may share the same `dispatch_key`.

4. Replace direct `WorkerJob::query()->create()` usage in:

```text
laravel/app/Http/Controllers/Workers/WorkerJobController.php
laravel/app/Http/Controllers/ReviewSuggestionController.php
laravel/app/Http/Controllers/EntityApprovalController.php
```

5. Add tests in `laravel/tests/Feature/Workers/WorkerJobDispatcherTest.php`.

Test cases:

- dispatch creates worker job
- dispatch writes audit log
- dispatch pushes `RunPythonWorkerJob`
- duplicate active dispatch returns existing job
- duplicate terminal dispatch creates new job
- `forceNew` creates new job
- retry links `retry_of_worker_job_id`
- `process_document` payload contains `paperless_document_id`
- `commit_review` queues through dispatcher
- `sync_entity_approval` queues through dispatcher

Commit:

```text
laravel: centralize worker job dispatch
```

---

## Phase 2: Lease and Heartbeat

### Goal

A job must not run twice in parallel. A running job must write heartbeats so recovery can detect crashes.

### Tasks

1. Create migration `add_lease_and_heartbeat_to_worker_jobs`.

Add:

```php
$table->string('worker_id')->nullable()->after('dispatch_attempts')->index();
$table->timestamp('lease_expires_at')->nullable()->after('worker_id')->index();
$table->timestamp('heartbeat_at')->nullable()->after('lease_expires_at');
```

2. Update `laravel/config/archibot_workers.php`.

Add:

```php
'lease_seconds' => (int) env('ARCHIBOT_WORKER_LEASE_SECONDS', 120),
'heartbeat_seconds' => (int) env('ARCHIBOT_WORKER_HEARTBEAT_SECONDS', 10),
'stale_running_minutes' => (int) env('ARCHIBOT_STALE_RUNNING_MINUTES', 10),
'pending_redispatch_seconds' => (int) env('ARCHIBOT_PENDING_REDISPATCH_SECONDS', 900),
'cancel_grace_seconds' => (int) env('ARCHIBOT_CANCEL_GRACE_SECONDS', 30),
```

3. Update `WorkerJob` fillable and casts for the new fields.

4. Add `markHeartbeat()` and `appendSystemLog()` helpers to `WorkerJob`.

5. Make `RunPythonWorkerJob` acquire jobs atomically.

The job should move from `queued` to `running` using a single conditional DB update. If no row is updated, return without starting Python.

Suggested flow:

```php
public function handle(PythonWorkerCommand $command): void
{
    $leaseOwner = $this->leaseOwner();
    $workerJob = $this->acquire($leaseOwner);

    if (! $workerJob) {
        return;
    }

    $command->run($workerJob, alreadyAcquired: true, leaseOwner: $leaseOwner);
}
```

6. Update `PythonWorkerCommand::run()` signature:

```php
public function run(
    WorkerJob $workerJob,
    ?WorkerResultIngestor $ingestor = null,
    bool $alreadyAcquired = false,
    ?string $leaseOwner = null,
): WorkerJob
```

If already acquired, the job should already be `running`.

7. During the process loop, update heartbeat every configured heartbeat interval.

8. At process start, store `process_id` and write a system log.

9. At the end, clear lease fields and write terminal status.

Tests in `laravel/tests/Feature/Workers/WorkerJobLeaseTest.php`:

- queued job is atomically acquired
- already acquired job is not acquired by a second queue job
- cancelled queued job is not acquired
- running job receives heartbeat
- terminal job clears lease

Commit:

```text
laravel: add worker job lease and heartbeat
```

---

## Phase 3: Recover Lost Jobs

### Goal

Jobs must not remain stuck after crashes or restarts.

### Tasks

1. Create `laravel/app/Services/Workers/WorkerJobRecovery.php`.

Methods:

```php
public function recoverAll(?int $pendingSeconds = null, ?int $runningMinutes = null): array
public function redispatchStaleQueued(?int $pendingSeconds = null): int
public function recoverStaleRunning(?int $runningMinutes = null): array
public function cancelStaleCancelling(?int $minutes = null): int
```

`recoverAll()` returns:

```php
[
    'redispatched_queued' => 0,
    'requeued_running' => 0,
    'failed_running' => 0,
    'cancelled_cancelling' => 0,
]
```

2. Redispatch stale queued jobs.

Rule:

```text
status = queued
dispatched_at is null OR dispatched_at < now - pending_seconds
```

Action:

```php
RunPythonWorkerJob::dispatch($job->id);
$job->markDispatched();
$job->appendSystemLog('worker_job.redispatched', 'Queued worker job was redispatched by recovery.');
```

3. Recover stale running jobs.

Rule:

```text
status = running
heartbeat_at is null OR heartbeat_at < now - running_minutes
```

If `dispatch_attempts` is below configured `archibot_workers.max_dispatch_attempts`, clear lease metadata, set back to queued and dispatch again.

If attempts are exhausted, clear lease metadata and mark failed with an error/progress reason of `stale_running_timeout`.

4. Integrate stale cancelling cleanup by reusing or refactoring `StaleWorkerJobCanceller`.

5. Create command `laravel/app/Console/Commands/RecoverWorkerJobs.php`.

Signature:

```php
protected $signature = 'worker-jobs:recover
    {--pending-seconds= : Redispatch queued jobs older than this}
    {--running-minutes= : Recover running jobs with stale heartbeat}
    {--dry-run : Show what would be recovered without changing rows}';
```

6. Add tests in `laravel/tests/Feature/Workers/WorkerJobRecoveryTest.php`.

Test cases:

- redispatches queued job with null `dispatched_at`
- does not redispatch fresh queued job
- requeues stale running job when `dispatch_attempts` is below configured max dispatch attempts
- fails stale running job when dispatch attempts are exhausted
- stale cancelling job becomes cancelled
- command prints summary

Commit:

```text
laravel: recover stale worker jobs
```

---

## Phase 4: Run Recovery Automatically

### Goal

Recovery must run periodically without requiring a user to open the UI.

### Tasks

1. Update `entrypoint.sh` after starting the queue worker:

```sh
echo "Starting Laravel worker job recovery loop"
(
  while true; do
    php artisan worker-jobs:recover --no-interaction || true
    sleep "${ARCHIBOT_WORKER_RECOVERY_INTERVAL_SECONDS:-30}"
  done
) &
```

2. Track the last successful recovery run and last recovery error.

Short-term options:

- AppSetting
- AuditLog
- small dedicated table

3. Create `docs/developer/worker-jobs.md`.

Document:

- How jobs run
- Required processes
- Relevant environment variables
- How recovery works
- How to debug stuck jobs

Commit:

```text
infra: run worker job recovery loop
```

---

## Phase 5: Harden Cancel and Force Kill

### Goal

Cancel must be reliable. Jobs must not remain stuck in `cancelling`.

### Tasks

1. Update `WorkerJobController::stop()`.

For running jobs:

```php
$workerJob->forceFill([
    'status' => WorkerJob::STATUS_CANCELLING,
    'cancellation_requested_at' => now(),
    'kill_after_at' => now()->addSeconds(config('archibot_workers.cancel_grace_seconds', 30)),
])->save();
```

Write system log `worker_job.cancel_requested`.

2. Update `PythonWorkerCommand::signalIfCancelling()`.

- If `cancel_signal_sent_at` is null, send SIGINT and set `cancel_signal_sent_at`.
- If `kill_after_at` is exceeded, stop or kill the process.

Minimum:

```php
$process->signal(2); // SIGINT
$process->stop(5, 15); // SIGTERM after timeout
```

3. Add force kill route:

```php
Route::post('worker-jobs/{workerJob}/force-kill', [WorkerJobController::class, 'forceKill'])
    ->name('worker-jobs.force-kill');
```

Rules:

- admin only
- only running or cancelling jobs
- record `force_killed_by_admin` in existing error/progress metadata unless a later migration adds a dedicated terminal reason field
- stop process if possible
- status becomes cancelled or failed
- write audit log

Tests:

- stop queued -> cancelled
- stop running -> cancelling plus kill_after_at
- stale cancelling -> cancelled by recovery
- force kill sets terminal status

Commit:

```text
laravel: harden worker job cancellation
```

---

## Phase 6: Idempotent Result Ingest

### Goal

Retries and duplicate outputs must not create duplicate data.

### Tasks

1. Add or verify dedupe for `review_suggestions`.

Suggested dedupe key:

```text
paperless_document_id + source_suggestion_id
```

Fallback if no source suggestion ID exists:

```text
paperless_document_id + content_hash + proposed_title + proposed_date + proposed entities
```

2. In `WorkerResultIngestor`, use `updateOrCreate()` instead of blind `create()`.

3. Add OCR review dedupe.

Suggested key:

```text
paperless_document_id + sha256(ocr_content)
```

4. Ensure `EntityApproval` dedupe by `type + name`.

5. Ingest partial output if `output.json` exists and contains valid JSON, even when the Python process exits non-zero.

Tests:

- same result ingested twice does not duplicate review suggestions
- retry commit does not duplicate commit state
- partial output is ingested even when process exits non-zero

Commit:

```text
laravel: make worker result ingest idempotent
```

---

## Phase 7: Worker Job Detail UI

### Goal

Admins can inspect every job in detail.

### Tasks

1. Add route:

```php
Route::get('worker-jobs/{workerJob}', [WorkerJobController::class, 'show'])
    ->name('worker-jobs.show');
```

2. Implement `WorkerJobController::show()`.

Return:

- job metadata
- payload
- progress
- result
- ingest summary
- paginated logs
- review suggestions
- retry parent
- retry children
- lease/heartbeat
- audit logs
- input/output path existence
- download URLs

3. Create:

```text
laravel/resources/js/pages/worker/Show.svelte
```

Sections:

- Header with job number, type, status and actions
- Reliability data: dispatch attempts, worker ID, lease expiry, heartbeat, last dispatch, terminal error/progress reason
- Progress
- Payload JSON
- Result JSON
- Logs with filters and pagination
- Related records

4. Update `Index.svelte` so job titles link to the detail page.

Tests:

- authenticated user can view job detail
- logs are shown
- admin sees actions
- non-admin cannot force kill

Commit:

```text
laravel: add worker job detail view
```

---

## Phase 8: Full Worker Controls

### Goal

All old CLI functions must be available in Laravel.

### Old CLI Functions to Map

From `docs/developer/cli.md`:

```text
archibot poll
archibot poll --force
archibot process-doc <id>
archibot process-doc <id> --force
archibot reindex
archibot reindex-ocr
archibot reindex-ocr --force
archibot reindex-embed
archibot jobs list
archibot jobs status
archibot jobs stop
archibot jobs retry
archibot reset --yes
archibot reset --yes --include-config
```

Laravel target:

- poll -> Worker Job button
- poll --force -> Worker Job button
- process-doc -> form
- process-doc --force -> checkbox
- reindex -> button
- reindex-ocr -> button
- reindex-ocr --force -> checkbox/button
- reindex-embed -> button
- jobs list/status -> WorkerJob Index/Show
- jobs stop -> Stop button
- jobs retry -> Retry button
- reset -> Admin Maintenance

### Tasks

1. Add force validation:

```php
'force' => ['nullable', 'boolean'],
```

2. Include force in payloads:

```php
TYPE_POLL => ['mode' => 'inbox', 'force' => $request->boolean('force')]
TYPE_PROCESS_DOCUMENT => ['paperless_document_id' => ..., 'force' => $request->boolean('force')]
TYPE_REINDEX_OCR => ['mode' => 'ocr', 'force' => $request->boolean('force')]
```

3. Update `PythonWorkerCommand::commandFor()` to pass `--force` for:

- poll
- process-document
- reindex-ocr

Keep `--input` and `--output`.

4. Add quick controls:

- Start inbox poll
- Start inbox poll force
- Process document ID
- Process document ID force
- Reindex full
- Reindex OCR
- Reindex OCR force
- Reindex embeddings

5. Add retry failed documents only.

Payload:

```php
[
    ...$workerJob->payload,
    'retry_document_ids' => [...],
    'retry_mode' => 'failed_only',
]
```

Tests:

- poll force payload
- process document force payload
- reindex OCR force payload
- `commandFor` passes `--force`
- retry failed documents only payload

Commit:

```text
laravel: expose full worker job controls
```

---

## Phase 9: Admin Maintenance Page

### Goal

Dangerous and administrative old CLI functions become safe Laravel admin features.

### Tasks

1. Create:

```text
laravel/app/Http/Controllers/Admin/MaintenanceController.php
laravel/resources/js/pages/admin/Maintenance.svelte
```

2. Add routes:

```php
Route::get('admin/maintenance', [MaintenanceController::class, 'index'])->name('admin.maintenance.index');
Route::post('admin/maintenance/recover-worker-jobs', [MaintenanceController::class, 'recoverWorkerJobs'])->name('admin.maintenance.recover-worker-jobs');
// Destructive reset is intentionally not routed through the GUI.
// Operators use: archibot reset --yes
// The Python CLI delegates to php artisan archibot:reset.
```

3. Implement features:

- Run worker recovery now
- Start poll reconciliation
- Start reindex
- Mark embedding index stale

4. Reset safety:

Destructive reset controls are intentionally excluded from the GUI. Operators use the stable ArchiBot CLI command:

```bash
archibot reset --yes
archibot reset --yes --include-config
```

The Python CLI delegates to `php artisan archibot:reset` and clears Laravel/PostgreSQL operational state, including worker-job, queue, pipeline, embedding, audit, chat, session/cache, webhook, review, OCR and entity-approval state.

Tests:

- non-admin forbidden for GUI maintenance controls
- reset route not available through the GUI
- CLI reset clears Laravel worker-job state

Commit:

```text
laravel: add admin maintenance controls
```

---

## Phase 10: GUI Parity Matrix

### Goal

Systematically ensure no old `/app` feature is missing.

### Tasks

Create:

```text
docs/laravel-gui-parity.md
```

Structure:

```md
# Laravel GUI Parity Matrix

## Source of truth

Old Python functionality is identified from:
- app/api_data.py
- app/cli.py
- app/worker.py
- docs/developer/cli.md

## Matrix

| Area | Old functionality | Laravel route/page | Status | Missing | Test |
|---|---|---|---|---|---|
```

Areas:

- Dashboard
- Inbox
- Review Queue
- Review Detail
- Entity Approvals: Tags
- Entity Approvals: Correspondents
- Entity Approvals: Doctypes
- OCR Reviews
- Embeddings
- Stats
- Errors
- Chat
- Settings
- Setup
- Audit Logs
- Worker Jobs
- Maintenance
- Webhooks
- MCP

Status values:

```text
done
partial
missing
deprecated
replaced-by-event-driven
```

Every `partial` or `missing` row must include a concrete follow-up task.

Commit:

```text
docs: add laravel gui parity matrix
```

---

## Phase 11: Dashboard and Health

### Goal

Dashboard and health checks must show whether Archibot can actually process jobs.

### Tasks

Dashboard should show:

- queued worker jobs
- running worker jobs
- cancelling worker jobs
- failed worker jobs
- stale queued jobs
- stale running jobs
- last recovery run
- last successful job
- last failed job
- document processing active
- reindex active
- Paperless configured
- user Paperless token present
- Ollama/provider configured
- embedding index ready
- OCR mode
- active provider roles
- recent errors

`/healthz` should return a structured result:

```json
{
  "status": "ok|degraded|error",
  "checks": {
    "database": "ok",
    "queue": "ok|unknown|error",
    "worker_recovery": "ok|stale|unknown",
    "paperless_config": "ok|missing",
    "python_runtime": "ok|error"
  }
}
```

Avoid heavy external calls in health checks.

Tests:

- healthz ok when DB is reachable
- healthz degraded when recovery is stale
- dashboard includes worker readiness

Commit:

```text
laravel: improve dashboard and health checks
```

---

## Phase 12: Document Job-Control Architecture

### Goal

Prevent future drift and keep the repo moving toward the event-driven target.

### Tasks

1. Create:

```text
docs/decisions/0012-worker-jobs-as-temporary-control-plane.md
```

Content:

```md
# ADR-0012: Worker jobs as temporary Laravel control plane

## Status

Accepted

## Context

Archibot currently has multiple job-control models. The Python core is functional, but Laravel job control needs stabilization.

## Decision

Use `worker_jobs` as a temporary stabilization layer. Harden it with dispatch centralization, dedupe, lease, heartbeat, recovery and UI controls.

The long-term control plane remains `commands`, `pipeline_runs`, `pipeline_events`, Absurd.

## Consequences

- Short-term reliability improves without rewriting the Python core.
- No new permanent product architecture should be built solely on `worker_jobs`.
- Each new worker_jobs feature must have a migration path to pipeline_runs.
```

2. Create:

```text
docs/architecture/job-control-model.md
```

Include:

- Current temporary model
- Future event-driven model
- Mapping table
- State machines
- Ownership rules
- Migration plan

Mapping examples:

```text
worker_jobs.type=poll -> command poll_reconciliation / pipeline run reconciliation
worker_jobs.type=process_document -> pipeline_run type=document
worker_jobs.type=reindex -> command reindex / pipeline_run type=reindex
worker_jobs.type=commit_review -> command commit_review
worker_jobs.type=sync_entity_approval -> command sync_entity_approval
```

3. Update `AGENTS.md` if present:

```md
Laravel `worker_jobs` is a temporary stabilization layer.
Do not add permanent architecture only to worker_jobs.
New durable pipeline functionality should target commands, pipeline_runs, pipeline_events and Python actors.
```

Commit:

```text
docs: document job control architecture
```

---

## Phase 13: Prepare Pipeline Runs as Final Job Truth

Start this only after Phases 1-12 are stable.

### Goal

Pipeline Runs become the durable job view that eventually replaces Worker Jobs.

### Tasks

1. Add Pipeline Run UI:

```text
/pipeline-runs
/pipeline-runs/{id}
```

Show:

- status
- type
- scope
- trigger_source
- paperless_document_id
- progress
- events
- items
- retry/cancel controls
- linked command
- linked webhook delivery

2. Every manual action should create:

```text
Command -> PipelineRun -> Queue/Actor message -> PipelineEvents
```

3. After this phase, new durable features should no longer be built only on `worker_jobs`.

Commit:

```text
laravel: expose pipeline runs as durable job view
```

---

## Phase 14: Move Processing to Absurd actors

### Goal

Python jobs stop running as Laravel subprocesses and move to actors.

### Tasks

1. Ensure actor modules exist or add them:

```text
app/actors/webhook.py
app/actors/document.py
app/actors/ocr.py
app/actors/embedding.py
app/actors/review.py
app/actors/maintenance.py
```

2. Add `actor_executions` table if missing.

Fields:

- id
- pipeline_run_id
- actor_name
- message_id
- queue_name
- status
- attempt
- started_at
- finished_at
- duration_ms
- error

3. Migrate single-document processing first:

```text
process_document command
-> pipeline_run
-> fetch_document actor
-> ocr actor
-> embedding actor
-> classify actor
-> review_suggestion actor
```

Acceptance criteria:

- A manually started document runs fully through Absurd.
- Laravel shows PipelineEvents.
- Retry does not duplicate suggestions.
- The old `worker_jobs` process-document path can be disabled.

4. Then migrate commit review.

5. Then migrate reindex.

Commit:

```text
pipeline: migrate document processing to actors
```

---

## Phase 15: Remove Legacy Worker Control Path

Start this only when actor flows are stable.

### Tasks

1. Remove or mark debug-only:

- `RunPythonWorkerJob`
- `PythonWorkerCommand`
- productive `worker_jobs` routes

2. Keep `app.cli` only for:

- debug
- maintenance
- local smoke tests

3. Update docs:

- `docs/developer/cli.md`
- `docs/architecture/job-control-model.md`
- `docs/implementation-plan-event-driven-archibot.md`
- `AGENTS.md`
- `README.md`

Commit:

```text
cleanup: remove legacy worker job control path
```

---

## Test Strategy

After every phase:

```bash
cd laravel
php artisan test
```

When Python is touched:

```bash
pytest
```

Worker-specific test filters:

```bash
php artisan test --filter=WorkerJob
php artisan test --filter=WorkerJobDispatcher
php artisan test --filter=WorkerJobLease
php artisan test --filter=WorkerJobRecovery
```

Container smoke:

```bash
docker compose build archibot
docker compose up
curl http://localhost:8088/healthz
```

## Commit Order

Use this order unless a phase must be split into smaller commits:

```text
1. laravel: centralize worker job dispatch
2. laravel: add worker job lease and heartbeat
3. laravel: recover stale worker jobs
4. infra: run worker job recovery loop
5. laravel: harden worker job cancellation
6. laravel: make worker result ingest idempotent
7. laravel: add worker job detail view
8. laravel: expose full worker job controls
9. laravel: add admin maintenance controls
10. docs: add laravel gui parity matrix
11. laravel: improve dashboard and health checks
12. docs: document job control architecture
13. laravel: expose pipeline runs as durable job view
14. pipeline: migrate document processing to actors
15. cleanup: remove legacy worker job control path
```

## Initial Pi.dev Instruction

Use this as the first agent instruction:

```md
Do not execute this historical plan. Follow `docs/implementation-plan-security-architecture-hardening.md` and accepted ADRs instead.
```
