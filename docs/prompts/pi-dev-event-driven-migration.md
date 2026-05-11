# pi.dev Prompt: Event-driven Archibot Migration

Use this prompt as the central instruction set for pi.dev/Codex when starting the event-driven Archibot migration.

Repository:

```text
pfriedrich84/archibot
```

## Working Mode

This repository/tool is actively in development. It is acceptable to work directly on `main` unless the operator explicitly asks for a branch.

The agent does **not** need to stop after the foundation phases. Work as far as possible toward the full documented target architecture, as long as changes remain coherent, reviewable and aligned with the non-negotiable rules.

Make small, logically grouped commits.

The agent may commit after each phase or logical milestone, but must ensure CI/tests are green before moving on to the next phase.

Parallelization through subagents is allowed and encouraged when it keeps changes reviewable and avoids conflicting edits.

The agent must actively manage its own context and the context of subagents. Keep shared contracts, decisions, open tasks and phase progress summarized in repository files so later subagents do not rely on hidden chat history.

## Mission

Migrate Archibot toward the documented event-driven target architecture.

Archibot is moving from the current Laravel-subprocess/Python-CLI/APScheduler/PostgreSQL/pgvector architecture to:

- Paperless webhooks as the primary trigger
- periodic polling every 600 seconds as reconciliation/fallback
- PostgreSQL + pgvector as the shared source of truth
- RabbitMQ as the Dramatiq broker
- Python Dramatiq actors as the pipeline engine
- durable progress, retries, locks and recovery
- centralized structured observability
- the existing Laravel dashboard as the admin operations console
- admin-only job control via `is_admin()`

## Read First

Before changing code, read these documents carefully:

```text
docs/implementation-plan-event-driven-archibot.md

docs/architecture/webhook-polling-coordination.md
docs/architecture/embedding-readiness-gate.md
docs/architecture/failure-retry-recovery.md
docs/architecture/retry-concept.md
docs/architecture/reprocess-triggers.md
docs/architecture/progress-tracking.md
docs/architecture/observability-logging.md
docs/architecture/authorization-job-control.md

docs/decisions/0006-require-complete-embedding-index-before-document-processing.md
docs/decisions/0007-keep-periodic-polling-with-webhook-dedupe-locks.md
docs/decisions/0008-use-durable-retries-and-recovery-for-pipeline-failures.md
docs/decisions/0009-use-structured-centralized-observability.md
docs/decisions/0010-use-durable-progress-tracking.md
docs/decisions/0011-require-admin-authorization-for-job-control.md
```

If `AGENTS.md` does not exist or does not reflect the current architecture, create/update it first.

If the earlier ADRs are missing, create them before or alongside implementation:

```text
docs/decisions/0001-use-dramatiq.md
docs/decisions/0002-use-postgresql-pgvector.md
docs/decisions/0003-use-rabbitmq.md
docs/decisions/0004-no-legacy-compatibility-mode.md
docs/decisions/0005-use-webhooks-as-primary-trigger.md
```

## Non-negotiable Architecture Rules

1. Do not extend the legacy Laravel-subprocess/Python-CLI worker path.
2. Do not introduce a long-term legacy compatibility mode.
3. Paperless webhooks are the primary trigger.
4. Polling remains automatic every 600 seconds as reconciliation/fallback.
5. Webhooks and polling must use the same pipeline-start/dedupe/lock logic.
6. No document processing may start before the embedding index is complete.
7. Webhooks may be accepted and persisted before the embedding index is complete, but processing must remain blocked/pending.
8. PostgreSQL is the source of truth for state, progress, retries and audit data.
9. RabbitMQ/Dramatiq is execution transport, not the only job state.
10. Progress must be durable and reconstructable after restart.
11. Actors must be idempotent and retry-safe.
12. Only admins may control jobs. All job-control API actions must check `is_admin()`.
13. Every admin-only job-control function must have a Laravel UI button/action for admins.
14. Python workers do not decide user authorization. Authorization happens at the Laravel/API boundary.
15. Do not log secrets, API keys, full OCR text, full document contents or full LLM prompts.
16. Per-document reprocess must be possible manually through Laravel and automatically through relevant Paperless webhooks.
17. Manual per-document reprocess must have an admin-only Laravel button on the document detail page.
18. The existing Laravel dashboard must be extended/reused. Do not invent a separate new operations UI unless explicitly requested.
19. CI/tests must be green before moving from one phase/logical milestone to the next.
20. Subagent work must be coordinated through explicit shared contracts and durable notes in the repo, not hidden context.

## Parallelization / Subagents

Parallelization is allowed and encouraged when it keeps changes reviewable.

You may use subagents for independent tracks, for example:

```text
Subagent A: Governance, AGENTS.md, ADRs, docs
Subagent B: Docker/infrastructure, PostgreSQL, RabbitMQ, pgvector
Subagent C: Laravel migrations/models/API/webhook endpoint
Subagent D: Python Dramatiq actors, broker config, worker bootstrap
Subagent E: Progress/retry/recovery helpers and tests
Subagent F: Existing Laravel dashboard buttons/actions and authorization tests
Subagent G: Document pipeline migration
Subagent H: Reconciliation/polling and reindex migration
```

Rules for parallel work:

- Work on `main` is allowed for this development tool unless the operator asks otherwise.
- Keep commits small and reviewable.
- Commits are allowed after each phase or logical milestone.
- Before starting the next phase or milestone, run the relevant tests/CI checks and ensure they are green.
- If CI fails, fix CI before continuing with new feature work.
- Avoid overlapping edits to the same files where possible.
- Do not let subagents create conflicting schemas or duplicate abstractions.
- Shared contracts must be written down before multiple agents implement against them.
- Architecture decisions must be reflected in docs/ADRs.
- If two subagents need the same interface, define the interface first.
- Do not merge partial implementations that violate the non-negotiable rules.

## Context Management for Subagents

The agent must actively manage context.

Before launching or delegating to subagents:

1. Create or update a short shared implementation outline.
2. Define shared contracts first: tables, statuses, event names, queues, helper names, API boundaries.
3. Assign each subagent a clear scope and file ownership where possible.
4. Record open decisions and assumptions in docs, ADRs or a phase notes file.
5. Keep summaries concise but durable in repository files.

Recommended durable context files:

```text
docs/governance/agent-workflow.md
docs/governance/review-checklist.md
docs/implementation-plan-event-driven-archibot.md
docs/prompts/pi-dev-event-driven-migration.md
```

If useful during implementation, create a temporary but committed phase note such as:

```text
docs/implementation-notes/event-driven-phase-status.md
```

This file may track:

- current phase
- completed commits
- open tasks
- schema contracts
- shared helper names
- known failing tests
- next safe step

Do not rely on hidden chat history for essential context.

## CI / Test Gate

After each phase or logical milestone:

1. Run the relevant test suite and linters available in the repo.
2. If GitHub Actions or another CI exists, ensure the latest commit is green before continuing.
3. If a test cannot be run locally, document why and provide the closest smoke check.
4. Do not continue with new feature work while known CI/test failures remain from your changes.
5. Keep the app runnable after each committed milestone.

Minimum expected checks, depending on what exists in the repo:

```text
Python tests / lint / type checks
Laravel/PHP tests
frontend/build checks if UI was changed
migration smoke checks
Docker compose startup smoke check
Dramatiq worker startup smoke check
webhook endpoint smoke check
```

## Implementation Strategy

Do not artificially stop at Phase 0-4. Implement as much of the target architecture as possible.

However, respect this order:

1. Governance and shared contracts.
2. Infrastructure and database foundation.
3. Webhook ingestion and durable state.
4. Dramatiq worker foundation.
5. Progress/retry/recovery/observability foundations.
6. Shared pipeline-start/dedupe/lock logic.
7. Embedding readiness gate and initial embedding build path.
8. Webhook-triggered document processing.
9. Polling/reconciliation every 600 seconds through the same pipeline-start logic.
10. Reprocess per document: manual admin button and webhook-triggered reprocess.
11. Reindex migration.
12. Legacy worker-path cleanup where safe.

Before implementing code, produce a short implementation outline with:

- files you intend to create/change
- database tables/migrations
- Laravel endpoints/controllers
- existing Laravel dashboard/UI areas to extend
- Python packages/modules
- tests/smoke checks

## Phase 0: Governance and Repo Guidance

Tasks:

- Create or update `AGENTS.md` as the central coding-agent entrypoint.
- Add or update governance docs under `docs/governance/` if missing:
  - `repository-governance.md`
  - `agent-workflow.md`
  - `review-checklist.md`
- Ensure ADRs exist for the documented architecture decisions.
- Make sure coding agents are instructed to follow the event-driven target architecture.

Required content in `AGENTS.md`:

```md
Archibot is being migrated to an event-driven architecture using Paperless webhooks, periodic polling reconciliation, Dramatiq, RabbitMQ, PostgreSQL and pgvector.
Paperless webhooks are the primary trigger; polling remains every 600 seconds as reconciliation/fallback.
Document processing must never start before the embedding index is complete.
Progress, retry and recovery state must be durable in PostgreSQL.
Only admins may control jobs via Laravel actions guarded by is_admin().
Per-document reprocess must be possible manually through an admin Laravel button and automatically through relevant webhooks.
Use the existing Laravel dashboard as the operations console. Extend it rather than creating a separate new UI.
Do not extend the legacy Laravel-subprocess/Python-CLI worker path.
```

## Phase 1: Infrastructure Foundation

Add local development infrastructure for:

- PostgreSQL with pgvector
- RabbitMQ
- optional observability hooks if easy, but do not overbuild

Update Docker/development configuration as appropriate.

Add required environment variables, for example:

```env
DATABASE_URL=postgresql+psycopg://archibot:archibot@postgres:5432/archibot
DRAMATIQ_BROKER_URL=amqp://guest:guest@rabbitmq:5672/
ARCHIBOT_QUEUE_PREFIX=archibot
PAPERLESS_WEBHOOK_SECRET=change-me
POLL_INTERVAL_SECONDS=600
LLM_PROVIDER=ollama
LLM_BASE_URL=http://ollama:11434
```

## Phase 2: Database Foundation

Add migrations/models for the new durable state model.

Minimum tables/models:

- `webhook_deliveries`
- `commands`
- `pipeline_runs`
- `pipeline_events`
- `actor_executions`
- `pipeline_items`
- `embedding_index_state`
- `llm_calls`
- `document_embeddings` with pgvector support

Include fields required by the docs, especially:

- durable progress fields
- retry fields
- status fields
- trigger source
- dedupe keys
- actor execution identifiers
- worker_id
- timestamps
- error fields
- reprocess fields

Recommended reprocess fields on `pipeline_runs`:

```text
reprocess_requested
reprocess_reason
reprocess_mode
reprocess_of_run_id
```

Important constraints/indexes:

- webhook delivery dedupe
- document pipeline dedupe
- paperless document id indexes
- status/time indexes for recovery scans
- pgvector index for embeddings where appropriate

## Phase 3: Webhook Ingestion

Add a Laravel Paperless webhook endpoint.

Suggested route:

```text
POST /api/webhooks/paperless
```

The endpoint must:

- validate webhook secret/header if configured
- validate payload shape
- persist raw payload in `webhook_deliveries`
- compute a webhook dedupe key
- deduplicate repeated deliveries
- write a `webhook.received` / `webhook.normalized` event where appropriate
- return quickly
- not perform OCR, embedding, classification or heavy Paperless/LLM work synchronously
- handle RabbitMQ unavailable without losing the persisted delivery

## Phase 4: Dramatiq Foundation

Add Python package structure:

```text
app/actors/
  __init__.py
  webhook.py
  document.py
  embedding.py
  maintenance.py

app/events/
  __init__.py
  types.py
  publish.py

app/jobs/
  __init__.py
  context.py
  progress.py
  locks.py
  idempotency.py
  retry.py
  recovery.py

app/db/
  session.py
  models.py
```

Add:

- Dramatiq broker configuration for RabbitMQ
- a webhook actor
- actor execution tracking
- structured logging context
- durable progress helper
- embedding readiness gate helper
- shared `start_or_attach_document_pipeline(...)`
- recovery scan
- retry classification

The first actor path should prove:

```text
webhook delivery -> persisted -> actor enqueued -> actor execution persisted -> pipeline event written
```

## Progress Control Requirements

Progress control is mandatory.

Progress is not just logging. Progress must be durable in PostgreSQL.

Hard rule:

```text
The UI must read progress from PostgreSQL state, not from logs or in-memory counters.
```

Support progress like:

```text
Embedding index: 10 / 130 completed, 0 failed
Current phase: embedding
Last update: 12:04:31
```

Minimum progress fields on `pipeline_runs`:

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

Minimum progress fields on `actor_executions`:

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

For multi-document work, use durable item-level state.

Recommended `pipeline_items` fields:

```text
pipeline_run_id
paperless_document_id
item_type
status              pending | running | succeeded | failed | skipped
attempt
error
started_at
finished_at
created_at
updated_at
```

Progress must be derived from durable item state where possible:

```text
total   = count(pipeline_items)
done    = count(status = succeeded)
failed  = count(status = failed)
skipped = count(status = skipped)
```

Do not blindly increment counters on retry.

Bad:

```text
retry item -> progress_done += 1 again
```

Good:

```text
mark item succeeded once -> derive done count from item states
```

For embedding builds, progress must continue after restart:

```text
progress_done = count(document_embeddings matching build scope/model/content_hash)
progress_total = discovered document count
```

Every meaningful progress update should be written to:

1. durable progress fields in PostgreSQL
2. `pipeline_events`
3. structured logs

Example structured progress log:

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

## Required Design Details

### Embedding Readiness Gate

Implement a shared gate helper:

```python
ensure_embedding_index_ready()
```

Behavior:

- allow processing only if `embedding_index_state.status == "complete"`
- otherwise mark work as blocked/pending
- emit `pipeline.blocked.embedding_index_not_ready`
- do not start document processing

### Webhook + Polling Coordination

Create a shared pipeline start service/function:

```text
start_or_attach_document_pipeline(trigger_source, paperless_document_id, paperless_modified, content_hash?)
```

It must handle:

- embedding gate
- document lock
- dedupe key
- existing active/completed run
- coalescing webhook/poll/manual/retry triggers
- enqueueing only when a new run is needed

### Polling

Keep automatic polling every 600 seconds.

Polling is reconciliation/fallback, not a separate competing processing path.

Polling and webhooks must both go through `start_or_attach_document_pipeline(...)` or the same equivalent service.

### Retry and Recovery

Implement:

- retry classification
- retry status fields
- recovery scan
- stuck running actor detection
- retryable vs permanent errors
- manual retry hooks for failed runs/items where practical

### Reprocess

Per-document reprocess must be possible through two paths:

```text
manual   -> admin clicks Laravel button
webhook  -> Paperless reports relevant document change
```

Manual reprocess requirements:

- admin-only
- Laravel document detail page must have a `Force reprocess` button/action
- backend must check `is_admin()` before command creation
- create a new pipeline run
- set `trigger_source = manual`
- set `reprocess_requested = true`
- set `reprocess_mode`
- respect document lock
- respect embedding readiness gate

Webhook-triggered reprocess requirements:

- no user-session `is_admin()` because this is not a user action
- webhook security still applies
- persist and dedupe webhook delivery
- use shared pipeline-start/dedupe/lock logic
- set `trigger_source = webhook`
- link `webhook_delivery_id`
- do not duplicate runs for duplicate deliveries or unchanged document state

Recommended manual reprocess modes:

```text
reprocess_metadata_only
reprocess_classification_only
reprocess_full_document_pipeline
reprocess_full_document_pipeline_force_embeddings
```

### Existing Laravel Dashboard / Operations UI

The Laravel dashboard already exists. Extend the existing dashboard and existing pages/components where appropriate.

Do not create a separate new operations UI unless explicitly requested.

Admin-facing job controls should be added to the existing dashboard/document/review/settings areas:

```text
Dashboard / operations overview:
- pipeline status
- embedding index status
- failed/retrying/blocked counts
- webhook delivery status
- polling/reconciliation status

Document detail page:
- Process document
- Retry document
- Force reprocess

Pipeline run detail/list:
- Retry failed items
- Cancel run

Embedding status area:
- Start embedding build
- Resume embedding build
- Mark embedding index stale

Maintenance/reindex area:
- Start reindex
- Run reconciliation now
- Run poll now

Webhook delivery list/detail:
- Retry webhook delivery
- Dismiss webhook failure

Review suggestion UI:
- Commit to Paperless

Entity approval UI:
- Sync entity approval

Settings/admin area:
- Save worker settings
- Save LLM settings
```

### Logging and Observability

Use structured logs.

Include IDs when available:

- request_id
- webhook_delivery_id
- pipeline_run_id
- actor_execution_id
- message_id
- paperless_document_id
- trigger_source
- queue_name
- actor_name
- worker_id

Do not log secrets or full document/LLM content.

### Admin-only Job Control

All Laravel endpoints that mutate jobs/pipelines must check:

```php
abort_unless(auth()->user()?->is_admin(), 403);
```

or the project-standard equivalent.

Required buttons/actions include:

- Process document
- Retry document
- Retry failed items
- Retry webhook delivery
- Cancel run
- Force reprocess
- Start embedding build
- Resume embedding build
- Mark embedding index stale
- Start reindex
- Run reconciliation now
- Run poll now
- Dismiss webhook failure
- Commit to Paperless
- Sync entity approval
- Save worker settings
- Save LLM settings

Buttons must be visible/enabled only for admins and protected by backend authorization.

## Tests / Smoke Checks

Add tests or documented smoke checks for:

- webhook delivery can be received and persisted
- duplicate webhook is deduped
- webhook endpoint returns quickly
- actor can be enqueued and processed
- actor execution is persisted
- pipeline event is persisted
- non-admin cannot call job-control endpoints
- admin can call job-control endpoints
- admin buttons render for admins
- non-admin buttons are hidden or disabled
- admin sees per-document `Force reprocess` button/action
- non-admin cannot use per-document `Force reprocess`
- manual reprocess creates a new pipeline run for a succeeded document
- webhook-triggered document change can create a new run
- duplicate webhook does not create duplicate reprocess run
- embedding gate blocks document processing when not complete
- poll/reconciliation and webhook use shared dedupe/lock path
- progress is stored in DB, not parsed from logs
- embedding progress can represent `10 / 130 completed`
- retry of a completed item does not double-count progress
- worker/container restart recovery can requeue safe stuck work

## Expected Output

Make small, reviewable commits.

At the end, provide:

1. Summary of changed files
2. Commands to run locally
3. Migrations to run
4. How to start PostgreSQL/RabbitMQ
5. How to trigger a test webhook
6. How to start the Dramatiq worker
7. Which tests/smoke checks pass
8. CI status / test status at each committed milestone
9. What remains, if anything, for the next phase

## Guardrails

Do not perform heavy document processing inside the webhook HTTP request.

Do not build a second operations UI; extend the existing Laravel dashboard.

Do not introduce a legacy/new backend feature flag such as:

```env
ARCHIBOT_WORKER_BACKEND=legacy|dramatiq
```

The architecture direction is replacement, not permanent dual-mode.

If a change risks breaking the running app, prefer a small safe step plus a documented smoke check over a huge unreviewable rewrite.

Do not continue to a new phase while CI/tests are red because of your changes. Fix the failures first.
