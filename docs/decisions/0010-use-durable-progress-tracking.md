# ADR-0010: Use Durable Progress Tracking

## Status

Accepted

## Context

Archibot has long-running operations such as initial embedding builds, reindex, polling/reconciliation and multi-document pipeline runs.

Users need reliable progress such as:

```text
Embedding build: 10 of 130 documents completed
```

This progress must survive:

- worker restarts
- container rebuilds
- retries
- partial failures
- cancellation

Logs alone cannot provide reliable progress because logs are operational, not source-of-truth state. In-memory counters are also insufficient because they reset on crash/restart.

## Decision

Progress is durable pipeline state in PostgreSQL.

Structured logs and pipeline events mirror progress, but the UI must read progress from PostgreSQL state rather than parsing logs.

Hard rule:

```text
Progress must be stored durably and must be reconstructable after restart.
```

## Required Model

Pipeline runs and actor executions should include durable progress fields.

Recommended `pipeline_runs` fields:

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

Recommended `actor_executions` fields:

```text
progress_total
progress_done
progress_failed
progress_skipped
progress_current_item
progress_message
progress_updated_at
worker_id
```

For multi-document work, item-level durable state is preferred.

Recommended table:

```text
pipeline_items
- id
- pipeline_run_id
- paperless_document_id
- item_type
- status              pending | running | succeeded | failed | skipped
- attempt
- error
- started_at
- finished_at
- created_at
- updated_at
```

Progress should be derived from item state where possible:

```text
total   = count(pipeline_items)
done    = count(status = succeeded)
failed  = count(status = failed)
skipped = count(status = skipped)
```

## Required Behavior

Actors must not blindly increment counters in a non-idempotent way.

Bad:

```text
retry completed item -> increment done again
```

Good:

```text
mark item succeeded once -> derive done count from item states
```

Every meaningful progress update should be written to:

1. durable progress fields in PostgreSQL
2. `pipeline_events`
3. structured logs

## Embedding Build Requirement

Initial embedding build must show progress such as:

```text
10 / 130 completed
```

If the worker restarts after 10 documents, progress must resume from 10 based on durable state, not from zero.

## Worker Identity

Each worker process should include a `worker_id` in logs and actor execution state.

This helps correlate progress with the worker that performed it.

## UI Requirement

Laravel should show progress from PostgreSQL:

- current phase
- done / total
- failed / skipped
- percentage
- progress message
- last update time
- blocked/retrying/cancelled state

## Consequences

What gets easier:

- progress survives restarts
- UI can show stable counters
- retries do not double-count
- embedding builds and reindex become observable

What gets harder:

- multi-document jobs need item-level state
- actors must update progress consistently
- tests must verify progress reconstruction

What must not be done anymore:

- relying on stdout/log parsing for UI progress
- relying on in-memory progress counters
- blindly incrementing counters without idempotency
- resetting progress after worker restart unless a new pipeline run starts
