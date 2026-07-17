# Job-Control Model

## Purpose

This document records the current event-driven ArchiBot job-control slice and the rules that prevent drift back to retired worker-job paths. It does not claim that every productive CLI/MCP/runtime path has completed migration.

`worker_jobs` was a hardened temporary stabilization layer. Per [ADR-0016](../decisions/0016-clean-install-worker-jobs-retirement.md), it has been retired for clean installs rather than preserved as backend/data compatibility. The active event-driven slice uses durable Laravel `commands`, `pipeline_runs`, `pipeline_events`, `pipeline_items`, `actor_executions`, webhook deliveries, audit logs, Laravel database queues, and fixed Python actor commands.

Until the milestones in [the security and architecture hardening plan](../implementation-plan-security-architecture-hardening.md) are complete, known parallel productive paths remain:

- legacy SQLite processing/search used by parts of Python CLI and MCP;
- Absurd actor decorators, queue workers, recovery bridge, schema and dependencies;
- Python Pipeline Start used by polling beside Laravel Pipeline Start;
- lifecycle/retry transitions split between Python domain actors and Laravel transport handling.

ADR-0017 makes the Laravel/PostgreSQL model below the sole target and requires deletion of those parallel paths after parity migration.

## Current event-driven durable model

```text
Maintenance UI / Dashboard / CLI / Paperless Webhook / Poll Scheduler
-> durable commands and/or pipeline_runs
-> pipeline_events / pipeline_items / actor_executions
-> Laravel database queue
-> fixed allowlisted Python actor command
-> PostgreSQL / pgvector
```

Current operator surfaces:

- **Maintenance** is the canonical admin action-launch surface.
- **Pipeline Runs** shows durable document/reprocess/retry/cancel state.
- **Operations Log** (`/operations-log`) shows durable commands, pipeline runs/events/items, actor executions, webhook deliveries and audit logs.
- **CLI overlap actions** (`archibot poll`, `reindex`, `reindex-ocr`, `reindex-embed`, `process-doc`) delegate to `php artisan archibot:maintenance-command ...`, the same backend used by Maintenance.
- **Reset** remains CLI-only and delegates to `php artisan archibot:reset`.

There is no `/worker-jobs`, `/legacy-worker-jobs`, `/operations-log/legacy-worker-jobs/{id}`, worker-job backend compatibility, or migration/archive path for old worker-job rows.

## Active action mapping

| Action | Durable owner | Transport/execution | Visibility |
|---|---|---|---|
| Poll reconciliation | `Command(type=poll_reconciliation)` | `RunPythonActorJob::pollReconciliation` -> `python -m app.actor_runner reconcile-poll --command-id ...` | Operations Log, command events |
| Full reindex | `Command(type=reindex)`; marks embedding gate stale | `RunPythonActorJob::reindex` -> fixed actor runner | Operations Log, embedding state/events |
| OCR reindex | `Command(type=reindex_ocr)` with `force` in payload | `RunPythonActorJob::reindexOcr` -> fixed actor runner | Operations Log, actor execution/events |
| Embedding build | `Command(type=embedding_index_build)` | `RunPythonActorJob::embeddingIndexBuild` -> fixed actor runner | Operations Log, embedding pages/state |
| Manual document process/reprocess | `PipelineRun(type=document, trigger_source=manual)` | `RunPythonActorJob::documentPipeline` -> fixed actor runner | Pipeline Runs, Operations Log |
| Paperless document webhook | `WebhookDelivery` + `PipelineRun(type=document)` | same document actor transport | Webhook Deliveries, Pipeline Runs, Operations Log |
| Review commit | `Command(type=review_commit)` | review commit actor | Review page, Operations Log, audit logs |
| Entity approval sync | `Command(type=sync_entity_approval)` | sync entity approval actor | Entity approval status, Operations Log |
| Automatic poll reconciliation | `php artisan schedule:work` -> `archibot:scheduled-poll` | due-check creates one durable poll command and dispatches its Laravel actor job | Operations Log, command events |
| Durable recovery scan | `php artisan archibot:recovery-scan` | recovers source-linked actor attempts, cancellations, and safe pending/stale commands/runs/webhooks through Laravel actor jobs | Pipeline/command/webhook/actor events |
| Reset | `php artisan archibot:reset` | Laravel/PostgreSQL-owned destructive reset | CLI output; reset remains outside GUI |

## State machines

### `commands`

```text
pending -> queued -> running -> succeeded
pending|queued|running -> failed
failed -> queued   (safe redispatch/retry where supported)
```

Command payloads are the durable source of truth for actor options such as `limit`, OCR `force`, review suggestion id, or entity approval id. Actor runner invocations should pass only stable ids (`--command-id`, `--pipeline-run-id`, `--webhook-delivery-id`) and load options from PostgreSQL.

### `pipeline_runs`

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
| `pending` | Request accepted and persisted; execution has not been enqueued yet. |
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

## Ownership rules

### Laravel owns UI, control, audit and readiness

Laravel is the operations console. It owns:

- admin authorization for job-control actions;
- Maintenance action launchers;
- command and pipeline-run creation;
- audit logging;
- readiness/health reporting;
- retry, cancellation and durable recovery controls;
- user-visible status, progress and logs read from PostgreSQL.

### Python owns document processing logic

Python owns:

- Paperless document processing behavior;
- OCR correction and embedding/classification logic;
- review suggestion generation;
- LLM/provider integration through the processing layer.

Laravel reaches Python through fixed, allowlisted actor commands. Operator-facing CLI commands that overlap GUI actions must not call the direct processing functions as an alternate backend; they must delegate to Laravel Maintenance.

### PostgreSQL is the source of truth

PostgreSQL stores durable command, pipeline, event, item, actor, webhook and audit state. Laravel queues are transport only. Recovery must redispatch from durable records rather than reconstruct work from queue state.

## Retired temporary model

The retired control flow was:

```text
Laravel UI
-> WorkerJobDispatcher
-> worker_jobs
-> RunPythonWorkerJob
-> Python CLI/Core
```

This model, including route helpers, models, migrations, queue job, dispatcher, result ingestor, stale cancellation, recovery commands, UI pages and tests, has been removed for clean installs. The old state machine and schema terms are historical only and must not be reintroduced.

## Rules for future contributors

- Do not add new `worker_jobs` functionality or compatibility storage.
- Do not add `/worker-jobs`, `/legacy-worker-jobs`, or legacy detail routes under `/operations-log`.
- Do not add GUI buttons that target retired worker-job routes, controllers or command names.
- Do not add top-level CLI escape hatches for GUI-overlapping actions; use Laravel Maintenance.
- New durable pipeline functionality should target `commands`, `pipeline_runs`, `pipeline_events`, `pipeline_items`, `actor_executions`, webhook deliveries, audit logs and fixed Python actors.
- Keep Laravel as the UI/control/audit/readiness owner.
- Keep Python as the document-processing owner.
- Keep PostgreSQL as the source of truth for progress, retries and recovery.
- Keep Laravel queues as execution transport, not the only state store.

## References

- [ADR-0016: Clean-install Retirement of Worker Jobs](../decisions/0016-clean-install-worker-jobs-retirement.md)
- [ADR-0012: Worker Jobs as Temporary Laravel Control Plane](../decisions/0012-worker-jobs-as-temporary-control-plane.md)
- [Event-driven implementation plan](../implementation-plan-event-driven-archibot.md)
- [Authorization for Job Control](authorization-job-control.md)
- [Durable Progress Tracking](progress-tracking.md)
- [Failure Retry Recovery](failure-retry-recovery.md)
