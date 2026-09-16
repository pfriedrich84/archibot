# Job-Control Model

## Purpose

This document records the current ArchiBot job-control model during the bounded migration to Temporal and the rules that prevent drift back to retired worker-job and queue paths.

`worker_jobs` was a hardened temporary stabilization layer. Per [ADR-0016](../decisions/0016-clean-install-worker-jobs-retirement.md), it has been retired for clean installs rather than preserved as backend/data compatibility. Per [ADR-0022](../decisions/0022-use-temporal-for-durable-workflow-orchestration.md), Temporal now owns embedding generation, poll discovery, per-document review production and review commits. Laravel database queues remain only for flows whose explicit cutover phase has not yet landed.

Productive SQLite processing and the former Python queue transport, decorators, workers, schema installer and dependencies are removed. ADR-0017 makes the Laravel/PostgreSQL model below the sole runtime path. Existing historical queue schema objects may remain inert on upgraded volumes solely for retention and rollback; they are never created on a clean install or used by current code.

## Current event-driven durable model

```text
Maintenance UI / Dashboard / CLI / Paperless Webhook / Poll Scheduler
-> Laravel command/pipeline projection + transactional Temporal outbox
-> stable Temporal workflow ID
-> idempotent Python activities
-> PostgreSQL / pgvector
```

Temporal is authoritative for workflow execution, retries, timers, heartbeats and recovery. PostgreSQL remains authoritative for business data, authorization decisions, audit records and UI projections. A poll command owns discovery only: each discovered document version receives an independent workflow and cannot be retained by the poll lifecycle.

Current operator surfaces:

- **Maintenance** is the canonical admin action-launch surface.
- **Pipeline Runs** shows durable document/reprocess/retry/cancel state.
- **Operations Log** (`/operations-log`) shows durable commands, pipeline runs/events/items, actor executions, webhook deliveries and audit logs.
- **CLI overlap actions** (`archibot poll`, `reindex`, `reindex-ocr`, `reindex-embed`, `process-doc`) delegate to `php artisan archibot:maintenance-command ...`, the same backend used by Maintenance.
- **Reset** is available to admins in Maintenance and through `php artisan archibot:reset`; both call the same Laravel/PostgreSQL reset service.

There is no `/worker-jobs`, `/legacy-worker-jobs`, `/operations-log/legacy-worker-jobs/{id}`, worker-job backend compatibility, or migration/archive path for old worker-job rows.

## Active action mapping

| Action | Durable owner | Transport/execution | Visibility |
|---|---|---|---|
| Poll reconciliation | `Command(type=poll_reconciliation)` plus transactional Temporal outbox intent | `PollReconciliationWorkflow` discovers global observations and starts detached document workflows | Operations Log, command events |
| Full reindex | `Command(type=reindex)` plus transactional Temporal outbox intent; marks embedding gate stale | `EmbeddingIndexWorkflow` with idempotent per-document activities | Operations Log, embedding state/events |
| OCR reindex | `Command(type=reindex_ocr)` with `force` in payload | `RunPythonActorJob::reindexOcr` -> fixed actor runner | Operations Log, actor execution/events |
| Embedding build | `Command(type=embedding_index_build)` plus transactional Temporal outbox intent; marks embedding gate stale | `EmbeddingIndexWorkflow` with Temporal retry and heartbeat | Operations Log, embedding pages/state |
| Manual document process/reprocess | `PipelineRun(type=document, trigger_source=manual)` plus transactional Temporal outbox intent | one stable `DocumentWorkflow` per requested version | Pipeline Runs, Operations Log |
| Paperless process-document webhook | `WebhookDelivery` + `PipelineRun(type=document)` plus transactional Temporal outbox intent | same stable document workflow path | Webhook Deliveries, Pipeline Runs, Operations Log |
| Review decision and commit | accepted/rejected suggestion plus transactional Temporal outbox intent; acceptance also creates `Command(type=review_commit)` | signal the waiting `DocumentWorkflow`; upgraded or legacy suggestions start a stable `ReviewCommitWorkflow` | Review page, Operations Log, audit logs |
| Entity approval application | `Command(type=sync_entity_approval)` | queued Laravel `ApplyEntityApprovalCommand`; PostgreSQL decision/recovery service, no Python/SQLite actor | Entity approval status, Operations Log, audit logs |
| Automatic poll reconciliation | Temporal Schedule `archibot/poll-reconciliation` | scheduled workflow atomically creates one command, then discovers documents | Temporal UI, Operations Log, command events |
| Durable recovery scan | `php artisan archibot:recovery-scan` | recovers remaining legacy actor attempts, cancellations, and safe pending/stale commands/runs/webhooks; Temporal-owned commands are excluded | Pipeline/command/webhook/actor events |
| Reset | `php artisan archibot:reset` or confirmed admin Maintenance action | Shared Laravel/PostgreSQL reset service | CLI/UI outcome and durable audit identity |

## State machines

### `commands`

```text
pending -> queued -> running -> succeeded|skipped|failed_permanent
running -> pending       (retry scheduled with next_retry_at)
failed -> queued         (explicit safe redispatch where supported)
```

Rejected entity names are read from PostgreSQL for every classification prompt; if that safety-state query is unavailable, classification fails closed and follows normal actor retry/recovery instead of silently omitting the blacklist. Entity approval decisions are persisted as queued, idempotency-keyed commands before any Paperless request. Enqueue locks the entity row and permits only one active decision across all actions: an identical action reuses its command, while a conflicting action returns a deterministic conflict. The entity and command share an active decision token and monotonically increasing version; workers condition every checkpoint and terminal write on that fence, so reordered, recovered, or superseded deliveries cannot overwrite a newer decision. The Laravel worker records the remote entity id before retroactive document patches. Recoverable failures become `pending` with `next_retry_at`; a worker death may leave `running`, and both paths are allowlisted for the same recovery scanner. Retries first resolve an existing Paperless entity by name, preventing duplicate entity creation across the create/persist crash window, while document patches are idempotent.

`failed_permanent` is terminal. `pending` with a future `next_retry_at` is the
actual command retry/backoff representation; it must not be displayed as an
immediately runnable queued command.

Command payloads are the durable source of truth for options such as `limit`, OCR `force`, review suggestion id, or entity approval id. Temporal workflow arguments and remaining actor runner invocations contain only stable row IDs and workflow identity; activities load protected state from PostgreSQL.

### `pipeline_runs`

```text
pending
  -> blocked
  -> queued
  -> running
  -> succeeded
  -> partially_failed
  -> failed

pending|blocked|queued|running|retrying
  -> cancel_requested
  -> cancelled

running -> retrying -> running
running -> failed_permanent
failed|partially_failed -> queued   (explicit operator retry)
```

State meanings:

| State | Meaning |
|---|---|
| `pending` | Request accepted and persisted; execution has not been enqueued yet. |
| `blocked` | Durable precondition is not satisfied, such as the embedding index readiness gate. |
| `queued` | A Temporal start intent exists or remaining legacy actor work has been enqueued. |
| `running` | A Temporal activity or remaining legacy actor execution is processing the run. |
| `retrying` | Failed work is being prepared for a new attempt. |
| `cancel_requested` | Admin cancellation has been requested and actors should stop cooperatively. |
| `cancelled` | Cancellation completed and no more work should run. |
| `succeeded` | All required work completed. |
| `partially_failed` | Some work completed and some failed, with durable details in events/items. |
| `failed` | A non-terminal legacy/operator-visible failure that may be explicitly retried. |
| `failed_permanent` | The bounded attempt budget or a permanent domain condition ended the run; no automatic retry is allowed. |

`pipeline_events` should explain every significant transition and progress update.
Actor executions use `pending -> queued -> running -> succeeded|skipped|blocked|retrying|failed_permanent|cancelled`;
`pending` is retained in the transition contract for pre-fence compatibility,
but the PostgreSQL fencing upgrade never leaves a pending winner active: it
atomically converts a directly retryable winner to a due `retrying` attempt and
a safe source recovery state. It suppresses only the obsolete attempt when its
source is terminal, blocked, or a process-document webhook owned by Pipeline
Start reconciliation.
Laravel recovery then redispatches one new `queued` claim. A retrying attempt is
terminal as an attempt, while the durable source carries the due time for a
later, separately fenced attempt. Migration fences use fixed-length deterministic
tokens so maximum-width bigint source and execution IDs still fit the 64-character
contract.

## Ownership rules

### Laravel owns UI, authorization, intent and audit

Laravel is the operations console. It owns:

- admin authorization for job-control actions;
- Maintenance action launchers;
- command and user/webhook pipeline-run creation with an outbox intent in the same transaction;
- audit logging;
- readiness/health reporting;
- authorized review decisions and cancellation intents;
- user-visible status, progress and logs read from PostgreSQL.

Poll discovery is the one additional reviewed creation seam: `app/temporal/document_activities.py` atomically records a global document observation and creates or safely adopts a pending legacy document run. The ownership guard permits this exact file and rejects every other unreviewed `pipeline_runs` insert.

### Temporal owns workflow execution

Temporal owns workflow scheduling, durable waits, retries, heartbeat timeouts and worker-loss recovery for migrated flows. A new document workflow waits for embedding readiness without occupying a worker, owns optional tag-gated OCR, target embedding, classification and judge, produces one idempotent review, and then waits for the authorized review signal. Its pipeline projection remains active while waiting. Acceptance executes the idempotent Paperless commit activity; rejection ends without a write; force reprocess ends the old workflow as superseded and starts a separate generation. Suggestions that predate a waiting document workflow use a stable standalone review-commit workflow. Laravel stale-actor recovery and old queued jobs explicitly refuse Temporal-owned commands and runs.

The polling interval is represented directly by a Temporal Schedule rather than a Laravel
minute-level due check. Model activities across embedding builds and document workflows use
one shared task queue with one process-wide execution slot. Document workflows may therefore
remain durably `Running` while waiting for model capacity or review, but neither wait occupies
a worker slot and no workflow execution timeout is used.

### Python owns document processing activities

Python owns:

- Paperless document processing behavior;
- OCR correction and embedding/classification logic;
- review suggestion generation;
- LLM/provider integration through the processing layer;
- idempotent Temporal activities and, during the bounded migration, remaining fixed actor commands.

Every fixed actor emits one final version-1 JSON record with protocol name `archibot.actor-outcome`. Its status is one of `succeeded`, `skipped`, `blocked`, `cancelled`, `retrying`, `failed-permanent` or `protocol-failure`, and its source contains only a durable `command`, `pipeline_run` or `webhook_delivery` id. Laravel validates that record and its source independently from process exit. A missing, malformed, mismatched-version or wrong-source record is a transport/protocol failure; it cannot rewrite Python-selected pending, blocked, retrying or terminal domain state.

Laravel reaches Python through fixed, allowlisted actor commands. Operator-facing CLI commands that overlap GUI actions must not call the direct processing functions as an alternate backend; they must delegate to Laravel Maintenance.

### PostgreSQL stores business state and projections

PostgreSQL stores commands, pipeline projections, global document observations, reviews, events, embeddings, webhook and audit state. Temporal stores workflow history and is authoritative for migrated execution state. Laravel recovery may recover only legacy-owned rows and never infers that a Temporal workflow is stale from projection timestamps.

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
- New durable pipeline functionality should use stable Temporal workflows and idempotent activities, with PostgreSQL projections and transactional Laravel outbox intents.
- Keep Laravel as the UI/control/audit/readiness owner.
- Keep Python as the document-processing owner.
- Keep PostgreSQL as the source of truth for business state, progress projections and audit.
- Keep Temporal as the only workflow retry, timer, heartbeat and recovery owner for migrated flows.

## References

- [ADR-0016: Clean-install Retirement of Worker Jobs](../decisions/0016-clean-install-worker-jobs-retirement.md)
- [ADR-0012: Worker Jobs as Temporary Laravel Control Plane](../decisions/0012-worker-jobs-as-temporary-control-plane.md)
- [ADR-0022: Use Temporal for Durable Workflow Orchestration](../decisions/0022-use-temporal-for-durable-workflow-orchestration.md)
- [Event-driven implementation plan](../implementation-plan-event-driven-archibot.md)
- [Authorization for Job Control](authorization-job-control.md)
- [Durable Progress Tracking](progress-tracking.md)
- [Failure Retry Recovery](failure-retry-recovery.md)
