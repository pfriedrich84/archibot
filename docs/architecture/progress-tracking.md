# Durable Progress Tracking

## Purpose

Archibot must show reliable progress for long-running worker operations such as embedding builds, reindex, polling/reconciliation and document pipelines.

Progress must survive worker restarts, container rebuilds and retries. Therefore progress is not only a log concern. It is durable pipeline state in PostgreSQL, mirrored into structured logs and user-visible events.

Example requirement:

```text
Embedding build: 10 of 130 documents completed
```

The counter must continue from the durable state after restart and must not reset to zero unless a new pipeline run starts.

## Principle

```text
PostgreSQL stores progress.
Pipeline events explain progress.
Structured logs mirror progress for operations/debugging.
UI reads progress from PostgreSQL.
```

Do not rely on in-memory counters or stdout logs as the source of truth.

## Progress Levels

Archibot tracks progress at multiple levels:

### Pipeline Run Progress

Overall progress for a command or pipeline.

Examples:

- initial embedding build
- reindex
- polling/reconciliation run
- bulk retry

### Actor Execution Progress

Progress inside a single actor execution.

Examples:

- embedding actor processing document 10 of 130
- reindex discover phase done
- OCR batch phase progress

### Phase Progress

Progress inside a logical phase.

Examples:

```text
phase = discover       130/130
phase = embedding      10/130
phase = classification 5/130
phase = review         2/130
```

For inbox polling, progress is phase-local by design. Operators should see the
current model phase and document counter, for example `ocr 4/19`, `embed 12/19`,
`classify 3/19`, `judge 2/7`, `store 8/19`, or `finalize 19/19`. The runtime may
also expose overall counters, but the phase counter is the primary signal
because model residency is phase-scoped.

Poll processing must batch model-heavy phases in this order to avoid excessive
model swaps:

```text
prepare -> ocr -> embed -> classify -> judge -> store -> postprocess -> finalize
```

Results are persisted after each document inside the active phase, not only at
phase completion. This gives crash recovery and clear failure attribution while
keeping model execution batched.

## Required Data Model

### `pipeline_runs` Progress Fields

Recommended fields:

```text
progress_total
progress_done
progress_failed
progress_skipped
progress_current_phase
progress_phase_total
progress_phase_done
progress_message
progress_updated_at
```

### `actor_executions` Progress Fields

Recommended fields:

```text
progress_total
progress_done
progress_failed
progress_skipped
progress_current_item
progress_message
progress_updated_at
```

### Optional `pipeline_progress_snapshots`

For long-running jobs where detailed history is useful, add snapshots.

```text
pipeline_progress_snapshots
- id
- pipeline_run_id
- actor_execution_id
- phase
- done
- total
- failed
- skipped
- message
- created_at
```

This table is optional. `pipeline_events` may be enough initially.

## Progress Event Types

Recommended event names:

```text
progress.started
progress.updated
progress.phase_started
progress.phase_completed
progress.item_started
progress.item_completed
progress.item_failed
progress.completed
```

Embedding-specific examples:

```text
embedding_index.started
embedding_index.progress
embedding_index.document_embedded
embedding_index.document_failed
embedding_index.completed
```

## Embedding Progress Example

Initial embedding build for 130 documents:

```text
embedding_index.started total=130 done=0 failed=0
embedding_index.progress total=130 done=1 failed=0 message="Embedded document 1 of 130"
embedding_index.progress total=130 done=10 failed=0 message="Embedded document 10 of 130"
embedding_index.completed total=130 done=130 failed=0
```

PostgreSQL state after 10 completed:

```text
pipeline_runs.progress_total = 130
pipeline_runs.progress_done = 10
pipeline_runs.progress_failed = 0
pipeline_runs.progress_current_phase = embedding
pipeline_runs.progress_message = "Embedded document 10 of 130"
```

## Restart Behavior

After container reboot:

1. Recovery scans `pipeline_runs` and `actor_executions`.
2. It identifies incomplete work.
3. It counts already completed durable outputs where necessary.
4. It resumes or requeues remaining work.
5. Progress continues from durable state.

For embedding builds, progress can be reconstructed from `document_embeddings` and the current build/run scope:

```text
progress_done = count(document_embeddings for this run/scope/model/content_hash)
progress_total = discovered document count
```

## Idempotency and Progress

Progress updates must be idempotent.

Bad:

```text
increment done blindly on retry
```

Good:

```text
mark document_id as completed for this run, then compute done count from completed rows
```

Recommended rule:

```text
Progress counters should be derived from durable item state where possible.
```

For batch-like jobs, keep an item-level state table or equivalent durable output so retries do not double-count.

## Item-Level State

For long-running multi-document work, prefer item-level state.

Example table:

```text
pipeline_items
- id
- pipeline_run_id
- paperless_document_id
- item_type
- item_key            stable per-run phase key for idempotent retries, e.g. `classification:123`
- status              pending | running | succeeded | failed | skipped
- attempt
- error
- started_at
- finished_at
- created_at
- updated_at
```

Document actor phases must use stable `item_key` values so retrying a crashed run resumes the same phase items instead of double-counting progress.

Then progress is derived as:

```text
total   = count(pipeline_items)
done    = count(status = succeeded)
failed  = count(status = failed)
skipped = count(status = skipped)
```

This avoids double-counting after retries or worker restarts.

## Worker Logging Requirements

Every meaningful progress update should be written to:

1. durable progress fields in PostgreSQL
2. `pipeline_events` for UI/audit visibility
3. structured logs for central observability

Example structured log:

```json
{
  "level": "info",
  "message": "embedding progress",
  "event_type": "embedding_index.progress",
  "pipeline_run_id": "run_...",
  "actor_execution_id": "exec_...",
  "phase": "embedding",
  "progress_done": 10,
  "progress_total": 130,
  "progress_failed": 0,
  "paperless_document_id": 123,
  "worker_id": "worker-1"
}
```

## Worker Identity

Each worker process should have a stable runtime identity.

Recommended field:

```text
worker_id
```

Examples:

```text
archibot-worker-1
hostname + process id
container id
```

`worker_id` should be included in:

- structured logs
- actor_executions
- recovery logs

## UI Requirements

Laravel should display:

- current phase
- done / total
- failed / skipped
- progress percentage
- current document if safe to display
- last progress update time
- worker id if useful for debugging
- blocked/retrying/cancelled state

Example UI text:

```text
Embedding index: 10 / 130 completed, 0 failed
Current phase: embedding
Last update: 12:04:31
```

## Polling/Reconciliation Progress

A poll run every 600 seconds should show:

```text
Discovery: 130 documents checked
Queued: 3 stale documents
Skipped: 127 up-to-date or already running
```

It should not show misleading progress as if it processed all documents itself if it only enqueued child document pipelines.

## Reindex Progress

Reindex should be phased:

```text
discover
embedding
classification/review if applicable
finalize
```

Each phase has its own done/total and the parent has aggregate counts.

## Cancellation and Progress

When cancellation is requested:

- keep last known progress
- set status to `cancel_requested`
- do not reset counters
- final state should show completed/failed/skipped/cancelled counts

## Test Requirements

Minimum tests:

- embedding build shows 10/130 after ten item successes
- retrying a completed item does not double-count progress
- worker restart reconstructs progress from durable state
- failed item increments failed count exactly once
- poll run reports discovered/queued/skipped counts
- cancellation preserves last progress
- structured logs include progress_done and progress_total
- UI/API can read progress from PostgreSQL without parsing logs

## Related Documents

- `docs/architecture/observability-logging.md`
- `docs/architecture/failure-retry-recovery.md`
- `docs/architecture/embedding-readiness-gate.md`
