# Event-driven Migration Phase Status

## Current target

Migrate Archibot toward the event-driven target architecture described in `docs/implementation-plan-event-driven-archibot.md` and governed by `docs/prompts/pi-dev-event-driven-migration.md`.

## Non-negotiable contracts

- Trigger sources: `webhook`, `poll`, `manual`, `retry`, `reindex`.
- Webhook endpoint: `POST /api/webhooks/paperless`.
- Poll interval default for the target architecture: `600` seconds.
- Queue prefix env: `ARCHIBOT_QUEUE_PREFIX`, default `archibot`.
- Initial queues: `archibot.webhook`, `archibot.io`, `archibot.llm`, `archibot.embedding`, `archibot.blocking`.
- Shared document pipeline helper name: `start_or_attach_document_pipeline(...)`.
- Embedding readiness helper name: `ensure_embedding_index_ready()`.
- Job-control authorization: Laravel boundary checks `is_admin()` before mutation.

## Phase progress

- Phase 0 governance: implemented, needs final review before commit.
  - `AGENTS.md` points agents to the event-driven migration rules.
  - Governance docs exist under `docs/governance/`.
  - ADRs `0001` through `0011` exist for the target decisions.
- Phase 1 infrastructure foundation: implemented as initial local/dev wiring, needs full Docker build smoke check.
  - PostgreSQL, RabbitMQ and pgvector-oriented environment settings were added to Docker/config examples.
  - Python config includes `DATABASE_URL`, `DRAMATIQ_BROKER_URL`, `ARCHIBOT_QUEUE_PREFIX` and `POLL_INTERVAL_SECONDS`.
  - Container runtime installs PHP pgsql support plus Dramatiq, SQLAlchemy, psycopg and pgvector Python dependencies.
  - Entrypoint starts Laravel, the Laravel queue worker, Dramatiq actors and the durable event recovery bridge when RabbitMQ is configured.
- Phase 2 durable database foundation: implemented as initial Laravel migration/models, needs broader Laravel test pass.
  - Tables/models exist for webhook deliveries, commands, pipeline runs/events/items, actor executions, embedding index state, LLM calls and document embeddings.
  - Progress, retry, trigger, dedupe, reprocess and observability fields are represented in the migration.
- Phase 3 webhook ingestion: implemented as persisted ingestion, no synchronous heavy work.
  - `POST /api/webhooks/paperless` validates the optional secret, extracts document id/modified time, computes a dedupe key, persists raw/normalized payloads and records received/duplicate events.
  - Feature tests cover persistence, dedupe, secret validation and malformed payload rejection.
- Phase 4 Dramatiq foundation: partially implemented.
  - RabbitMQ broker config, queue naming, actor/event/job package skeletons and webhook actor exist.
  - Webhook actor now loads persisted delivery state, emits normalization events, calls `start_or_attach_document_pipeline(...)`, and marks delivery `blocked`, `processed` or `failed`.
  - Recovery scan now finds queued webhook deliveries and enqueues the webhook actor through Dramatiq `.send(...)`, with a local fallback when Dramatiq is unavailable.
  - `archibot-events` / `python -m app.event_worker recovery-scan` provides a recovery-loop bootstrap for persisted queued work.
  - Shared pipeline-start helper computes dedupe keys, persists/attaches durable `pipeline_runs`, enforces the embedding readiness gate, and emits blocked/pending events.
  - Embedding readiness gate reads the latest durable `embedding_index_state.status` and only allows `complete`.
  - Recovery scan releases runs blocked by `embedding_index_not_ready` back to `pending` after the embedding index becomes complete.
  - Webhook actor records durable `actor_executions` rows for running/succeeded/blocked/failed outcomes.
  - Durable progress helpers can update `pipeline_runs` and `actor_executions` progress fields from actor snapshots.
  - Recovery scan now queues pending document pipeline runs for the document actor.
  - Document actor now loads durable run state, fetches the Paperless document read-only, records a `paperless_fetch` pipeline item, runs LLM classification using Paperless entity lists, records a `classification` pipeline item, persists a Laravel `review_suggestions` row for manual review, records a `review_suggestion` pipeline item, derives durable progress from item state, and marks the run succeeded without calling the legacy worker path.
  - Initial embedding-index actor now fetches Paperless documents, embeds them with Ollama, stores vectors in PostgreSQL/pgvector `document_embeddings`, updates durable progress, and marks the index `complete` when all embeddings succeed.
  - Polling reconciliation actor fetches Paperless inbox documents every configured poll interval and uses `start_or_attach_document_pipeline(...)`, so polling and webhooks share dedupe/gate/run logic.
  - Accepted review suggestions are picked up by recovery, enqueued to a review commit actor, patched to Paperless using reviewed IDs only, and marked committed/failed in `review_suggestions.commit_status`.
  - Accepting event-driven review suggestions now marks `commit_status=queued` without creating legacy worker jobs; legacy Python-origin suggestions still use the existing worker job path.
  - Admin-only manual reprocess is available from review detail pages and creates durable `pipeline_runs` with `trigger_source=manual` and reprocess metadata.
  - Relevant Paperless change/update webhooks now mark pipeline runs with automatic reprocess metadata (`reprocess_requested`, `reprocess_reason`, `reprocess_mode=webhook`) while create/delete events do not.
  - Existing Laravel dashboard now surfaces event-driven operations state: queued webhooks, active/blocked/failed pipeline runs, running/failed actor executions, recent actor executions, recent pipeline runs, progress counters, phase and reprocess markers.
  - `scripts/event_driven_smoke.py` provides a dependency-light local import/config/queue-name smoke check for event-driven Python contracts.
  - Admin-only pipeline run retry/cancel controls are available from the dashboard and write durable/audited status transitions for recovery pickup.
  - Recovery now finalizes `cancel_requested` pipeline runs to `cancelled` and emits `pipeline.cancelled` events.
  - Recovery now detects stale `running` actor executions left by worker/container crashes, marks them `retrying` with `retry_mode=recovery`, emits `actor.recovered_stale`, and requeues document pipeline runs when a safe `pipeline_run_id` is available.
  - Retry classification/backoff is now wired for document pipeline actors: transient network/provider/Paperless/rate-limit/recoverable-processing failures schedule durable `retrying` runs with bounded backoff, and recovery requeues retrying document runs when `next_retry_at` is due.
  - Admin-only embedding index controls are available from the dashboard: `Start/Resume embedding build` creates a durable `embedding_index_build` command for recovery pickup, and `Mark embedding index stale` updates durable readiness state and emits/audits the control action.
  - Recovery now bridges pending `embedding_index_build` commands to the embedding Dramatiq actor.
  - Admin-only `Run poll now` is available from the dashboard, creates a durable `poll_reconciliation` command, and recovery bridges it to the polling reconciliation actor so manual and scheduled polling share the same `start_or_attach_document_pipeline(...)` path.
  - Admin-only `Start reindex` is available from the dashboard, marks the durable embedding index state `stale` to close the processing gate, creates a durable `reindex` command, and recovery bridges it to the existing embedding rebuild actor as a safe initial reindex step.
  - Dashboard now shows recent webhook deliveries with event/document/status/error/timestamps. Admin-only `Retry webhook delivery` requeues failed/blocked deliveries for recovery pickup, and `Dismiss webhook failure` moves them to durable `dismissed` terminal state with events/audit records.
  - Dashboard now shows failed item counts for recent pipeline runs. Admin-only `Retry failed items` resets failed `pipeline_items` to pending with manual retry metadata, moves the parent run back to pending, emits a pipeline event/audit record, and relies on recovery to enqueue the run.
  - Laravel manual reprocess/retry controls now respect the embedding readiness gate: document runs are created or moved to `blocked` with `embedding_index_not_ready` until the latest durable embedding index state is `complete`.
  - Laravel webhook ingestion can now optionally attempt direct enqueue for newly persisted non-duplicate deliveries through `ARCHIBOT_WEBHOOK_DIRECT_ENQUEUE=true`. It uses `ARCHIBOT_PYTHON_BINARY` with the fixed command `-m app.event_worker enqueue-webhook --delivery-id <id>` instead of arbitrary argv. Failures keep the delivery `queued`, emit redacted `webhook.enqueue_deferred`, and rely on durable recovery fallback.
  - Recovery enqueue helpers now restore command bridges and document pipeline runs to discoverable `pending` state if broker `.send(...)` fails after a queued transition.
  - Full end-to-end enqueue proof against live RabbitMQ/PostgreSQL is still open.

## Current validation

- `ruff check app/ tests/`: passing.
- `ruff format --check app/ tests/`: passing.
- `pytest tests/ -q`: passing, 393 tests.
- Targeted recovery enqueue-failure validation: `ruff check app/jobs/recovery.py tests/test_recovery.py` and `pytest tests/test_recovery.py -q` passing, 20 tests.
- `COMPOSER_ALLOW_SUPERUSER=1 composer test` from `laravel/`: passing, 136 tests / 981 assertions. Targeted webhook direct-enqueue validation: `php artisan test tests/Feature/Webhooks/PaperlessEventWebhookTest.php` passing, 7 tests / 40 assertions. Targeted embedding-gate retry/reprocess validation: `php artisan test tests/Feature/Review/ReviewSuggestionTest.php tests/Feature/PipelineRunControlTest.php tests/Feature/Webhooks/PaperlessEventWebhookTest.php` passing, 36 tests / 236 assertions.
- `npm run format:check` from `laravel/`: passing.
- `npm run types:check` from `laravel/`: passing.
- `python3 scripts/event_driven_smoke.py`: passing.
- Targeted recovery validation: `ruff check app/ tests/test_recovery.py tests/test_actor_execution.py` and `pytest tests/test_recovery.py tests/test_actor_execution.py -q` passing, 15 tests.
- `python3 scripts/check_dependency_age.py --min-days 3`: passing, 77 packages.
- `bash -n entrypoint.sh`: passing.
- Docker build smoke check: not run locally because `docker` is unavailable in this environment.

## Open implementation notes

- Python currently has `app/db.py`, so the target `app/db/` package cannot be introduced without a later module migration. New PostgreSQL/Dramatiq code remains additive for now.
- Removed legacy Laravel webhook routes must not be extended or reintroduced for the new architecture. The target endpoint is `/api/webhooks/paperless` with `/webhook` as a simple alias.
- Laravel webhook ingestion persists durable delivery state, starts or coalesces a durable `pipeline_runs` row, and optionally attempts fixed direct enqueue when `ARCHIBOT_WEBHOOK_DIRECT_ENQUEUE=true`. Recovery scan and entrypoint remain the durable fallback from queued `webhook_deliveries` to Dramatiq actors; the next safe milestone is a live Docker/RabbitMQ/PostgreSQL smoke check.
- `ensure_embedding_index_ready()` now reads durable index status and fails closed unless it is `complete`. Blocked document runs are persisted and recoverable. The initial embedding build actor can now populate pgvector and mark builds `complete`; live Paperless/Ollama/PostgreSQL smoke testing is still needed.
- Pending document runs now reach a document actor and perform read-only Paperless fetch, LLM classification, and Laravel review suggestion persistence. Accepted suggestions now have an event-driven commit actor. Commit only uses reviewed existing IDs and preserves existing Paperless storage paths. Event-driven processing must preserve configured `auto_commit_confidence` behaviour.
- Manual admin reprocess now creates durable pending runs for recovery/Dramatiq pickup. Automatic webhook-triggered reprocess metadata is wired for Paperless change/update events; live webhook payload verification is still needed.
- Polling reconciliation is wired through the event worker and maintenance actor; live Paperless/RabbitMQ/PostgreSQL smoke testing is still needed outside this environment.
