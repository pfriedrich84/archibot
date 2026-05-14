# Job-Control Model

## Purpose

This document records the current Laravel job-control model, the future event-driven model and the rules that prevent drift between them.

`worker_jobs` is a hardened temporary stabilization layer. It exists to make the current Laravel UI reliable while Archibot migrates to durable commands, pipeline runs, pipeline events, RabbitMQ and Dramatiq actors.

## Current Temporary Model

Current control flow:

```text
Laravel UI
-> WorkerJobDispatcher
-> worker_jobs
-> RunPythonWorkerJob
-> Python CLI/Core
```

Responsibilities in this temporary model:

1. Laravel validates the admin action and calls `WorkerJobDispatcher`.
2. `WorkerJobDispatcher` normalizes payloads, computes dedupe keys, writes audit logs, creates or reuses a `worker_jobs` row and dispatches `RunPythonWorkerJob`.
3. `RunPythonWorkerJob` acquires a lease and invokes the Python CLI/core for the requested worker job type.
4. The Python process performs document or maintenance work and emits structured progress/output.
5. Laravel ingests results, updates status/progress/logs and exposes the job in the UI.

This model is allowed to be reliable. It should not be treated as the permanent pipeline architecture.

## Current `worker_jobs` Schema Terms

Important control-plane fields:

| Field | Meaning |
|---|---|
| `dispatch_key` | Stable dedupe key derived from job type and normalized payload. Active jobs with the same key are reused instead of duplicated. |
| `dispatch_attempts` | Number of times Laravel has attempted to dispatch the queued backend job for this worker job. |
| `dispatched_at` | Timestamp of the most recent dispatch attempt. |
| `worker_id` | Identifier of the Laravel queue worker/process that acquired the job lease. |
| `lease_expires_at` | Time at which the current worker lease is considered expired if not renewed. |
| `heartbeat_at` | Last heartbeat written by the running job/process. Used for recovery and operational visibility. |

These fields make `worker_jobs` safer during the temporary period, but they are not the final pipeline state model.

## Why `worker_jobs` Is Temporary

`worker_jobs` wraps the current Laravel-subprocess/Python-CLI path. That path gives Laravel a usable control plane while the Python core still runs as CLI commands, but it is not the target architecture because:

- one large CLI execution is harder to split into idempotent, retryable actor steps;
- subprocess execution does not provide queue routing, dead-lettering or actor-level observability;
- progress and recovery should be expressed as durable pipeline events, not only worker job status and logs;
- Paperless webhooks, scheduler reconciliation and manual admin actions should converge on one command/pipeline model;
- Archibot must not keep parallel permanent job-control systems.

Therefore `worker_jobs` may be hardened for reliability, but permanent architecture must move to `commands`, `pipeline_runs`, `pipeline_events`, RabbitMQ and Dramatiq actors.

## Future Event-Driven Model

Target control flow:

```text
Laravel UI / Paperless Webhook / Scheduler
-> commands
-> pipeline_runs
-> pipeline_events
-> RabbitMQ
-> Dramatiq Actors
-> PostgreSQL / pgvector
```

In the target model:

- `commands` record requested control actions and their initiator.
- `pipeline_runs` are the durable user-visible unit of work.
- `pipeline_events` record state changes, progress, retries, failures and audit-relevant execution details.
- RabbitMQ is transport only; PostgreSQL remains the source of truth.
- Dramatiq actors perform durable processing steps and emit events.
- Laravel reads PostgreSQL for UI, readiness, job control and audit views.

## Mapping from `worker_jobs` to Future Commands and Pipeline Runs

| Current `worker_jobs.type` or action | Future command | Future `pipeline_runs` type/scope | Notes |
|---|---|---|---|
| `poll` | `poll_reconciliation` | `reconciliation` | Polling remains fallback/reconciliation, not the primary document trigger. |
| `process_document` | `process_document` or `reprocess_document` | `document` scoped to `paperless_document_id` | Manual and webhook-triggered document work should converge on document pipeline runs. |
| `reindex` | `reindex` | `reindex` | Full reindex should emit phase progress and block normal document processing when required. |
| `reindex_ocr` | `reindex_ocr` | `ocr_reindex` | OCR-specific reindex/review work becomes actorized OCR pipeline work. |
| `reindex_embed` | `reindex_embed` or `build_embedding_index` | `embedding_index` | Embedding readiness remains a hard gate before document processing. |
| `commit_review` | `commit_review` | `review_commit` scoped to review suggestion/document | Commit side effects must remain idempotent and audited. |
| `sync_entity_approval` | `sync_entity_approval` | `entity_approval_sync` scoped to entity approval | Entity approval sync becomes a durable command/pipeline action. |
| maintenance worker recovery | `recover_worker_jobs` then `recover_pipeline_runs` | `maintenance` / `recovery` | During migration this may repair `worker_jobs`; final recovery targets pipeline runs and actor executions. |
| maintenance reset | `maintenance_reset` | `maintenance_reset` | Destructive admin action remains Laravel-authorized and audited; execution should be durable and observable. |

## State Machines

### Current `worker_jobs` State Machine

```text
queued
  -> running
  -> succeeded
  -> partially_failed
  -> failed

queued
  -> cancelled

running
  -> cancelling
  -> cancelled

running
  -> failed        (process failure, lease recovery or unrecoverable error)
running
  -> queued        (recovery may requeue safely when no live worker owns the job)
cancelling
  -> failed        (force kill or cancellation failure)
```

Status groups:

- active: `queued`, `running`, `cancelling`
- terminal: `cancelled`, `succeeded`, `failed`, `partially_failed`

A `worker_jobs` row should eventually reach a terminal status unless recovery deliberately requeues it. Duplicate active jobs are prevented by `dispatch_key`.

### Future `pipeline_runs` State Machine

Recommended target state machine:

```text
pending
  -> blocked
  -> queued
  -> running
  -> succeeded
  -> partially_failed
  -> failed

pending|blocked|queued|running
  -> cancelling
  -> cancelled

failed|partially_failed
  -> retrying
  -> queued
```

State meanings:

| State | Meaning |
|---|---|
| `pending` | Command accepted and persisted; execution has not been enqueued yet. |
| `blocked` | Durable precondition is not satisfied, such as the embedding index readiness gate. |
| `queued` | Actor work has been enqueued or is ready to be enqueued. |
| `running` | At least one actor execution is actively processing the run. |
| `retrying` | Failed work is being prepared for a new attempt. |
| `cancelling` | Admin cancellation has been requested and actors should stop cooperatively. |
| `cancelled` | Cancellation completed and no more work should run. |
| `succeeded` | All required work completed. |
| `partially_failed` | Some work completed and some failed, with durable details in events/items. |
| `failed` | The run cannot continue without retry or operator action. |

`pipeline_events` should explain every significant transition and progress update.

## Ownership Rules

### Laravel Owns UI, Control, Audit and Readiness

Laravel is the operations console. It owns:

- admin authorization for job-control actions;
- UI buttons and pages for starts, retries, cancellation, commits and maintenance;
- command creation and audit logging;
- readiness/health reporting;
- user-visible status, progress and logs read from PostgreSQL.

### Python Owns Document Processing Logic

Python owns:

- Paperless document processing behavior;
- OCR correction and embedding/classification logic;
- review suggestion generation;
- LLM/provider integration through the processing layer.

In the temporary model this logic is reached through the CLI/core. In the target model it is reached through Dramatiq actors.

### Event-Driven Actors Own Durable Processing

Dramatiq actors will own durable execution of pipeline steps. Actors must:

- be idempotent;
- write durable progress/state through PostgreSQL-backed models;
- emit `pipeline_events`;
- honor cancellation and retry semantics;
- treat RabbitMQ as transport, not the source of truth.

## Migration Plan

1. Keep `worker_jobs` stable while Laravel job control is hardened.
2. For every current `worker_jobs` type, define the target command and `pipeline_runs` mapping before expanding behavior.
3. Introduce or complete `commands`, `pipeline_runs` and `pipeline_events` as the final job truth.
4. Make manual Laravel actions create commands and pipeline runs first, then enqueue actor work.
5. Move individual worker job types to Dramatiq actors one flow at a time.
6. Preserve UI parity during migration by linking old `worker_jobs` to new pipeline runs where needed.
7. Move recovery, retry, cancellation, progress and logs from worker-job-only semantics to pipeline-run/event semantics.
8. Stop adding new durable functionality exclusively to `worker_jobs` once a flow has a pipeline-run implementation.
9. Retire the Laravel-subprocess/Python-CLI worker path after equivalent actor flows are tested and visible in Laravel.

## Rules for Future Contributors

- Do not add permanent architecture only to `worker_jobs`.
- Do not create a third long-lived job-control system.
- New durable pipeline functionality should target `commands`, `pipeline_runs`, `pipeline_events` and Python actors.
- If a temporary `worker_jobs` change is necessary for reliability, document its migration path to pipeline runs.
- Keep Laravel as the UI/control/audit/readiness owner.
- Keep Python as the document-processing owner.
- Keep PostgreSQL as the source of truth for progress, retries and recovery.
- Keep RabbitMQ/Dramatiq as execution transport and actor runtime, not the only state store.

## References

- [ADR-0012: Worker Jobs as Temporary Laravel Control Plane](../decisions/0012-worker-jobs-as-temporary-control-plane.md)
- [Event-driven implementation plan](../implementation-plan-event-driven-archibot.md)
- [Laravel job-control implementation plan](../implementation-plan-laravel-job-control.md)
- [Authorization for Job Control](authorization-job-control.md)
- [Durable Progress Tracking](progress-tracking.md)
- [Failure Retry Recovery](failure-retry-recovery.md)
