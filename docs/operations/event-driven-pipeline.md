# Event-driven Pipeline Operations

This guide covers the new Archibot event-driven processing path. It is the target path for Paperless webhooks, polling reconciliation, embedding builds, reprocess, retry, reindex and review commits.

## Required services and environment

Required runtime services:

- PostgreSQL with `pgvector` enabled.
- Laravel database queue transport.
- Laravel HTTP app, queue worker and scheduler.
- Laravel-native pipeline recovery scan.
- Paperless and the configured LLM/embedding provider.

Core environment variables:

```env
DATABASE_URL=postgresql+psycopg://archibot:archibot@postgres:5432/archibot
QUEUE_CONNECTION=database
QUEUE_WORKER_TIMEOUT=21600
DB_QUEUE_RETRY_AFTER=21720
POLL_INTERVAL_SECONDS=600
ARCHIBOT_RECOVERY_INTERVAL_SECONDS=30
ARCHIBOT_STALE_RUNNING_MINUTES=10
PAPERLESS_WEBHOOK_SECRET=<generate-a-random-secret>
```

Laravel queue jobs use small durable identifiers such as command IDs, pipeline run IDs and webhook delivery IDs. Fixed Python actor commands perform the processing work and write durable state/events back to PostgreSQL.

Fixed actor-runner contracts:

```bash
python -m app.actor_runner build-embedding-index --command-id <commands.id>
python -m app.actor_runner process-document --pipeline-run-id <pipeline_runs.id>
python -m app.actor_runner reconcile-poll --command-id <commands.id>
python -m app.actor_runner reindex --command-id <commands.id>
python -m app.actor_runner reindex-ocr --command-id <commands.id>
python -m app.actor_runner handle-webhook --delivery-id <webhook_deliveries.id>
python -m app.actor_runner commit-review --command-id <commands.id>
python -m app.actor_runner sync-entity-approval --command-id <commands.id>
```

Laravel queued wrappers:

```php
RunPythonActorJob::embeddingIndexBuild($commandId)
RunPythonActorJob::documentPipeline($pipelineRunId)
RunPythonActorJob::pollReconciliation($commandId)
RunPythonActorJob::reindex($commandId)
RunPythonActorJob::reindexOcr($commandId)
RunPythonActorJob::webhookDelivery($deliveryId)
RunPythonActorJob::reviewCommit($commandId)
RunPythonActorJob::syncEntityApproval($commandId)
```

The embedding actor runner accepts only `--command-id`; options such as `limit` are loaded from the durable `commands.payload` row so the database command remains the single source of truth. Admin embedding-build requests now dispatch this Laravel queued wrapper directly; they no longer create a temporary `worker_jobs` fallback entry and no longer branch on Absurd configuration. Document pipeline starts dispatch the document actor wrapper with only a durable `pipeline_runs.id`; document processing, Paperless/LLM calls, retries and progress remain Python-owned and PostgreSQL-backed. ADR-0018 containment is implemented: the actor always stores model/judge results as pending Review Suggestions and cannot accept or queue a commit from confidence. Non-process webhook actions dispatch the webhook delivery wrapper with only a durable `webhook_deliveries.id`; Python loads the Laravel-normalized action and owns embedding refresh/delete execution. Admin poll reconciliation, full reindex, and OCR reindex controls create durable commands and dispatch the corresponding Laravel actor wrappers; Python loads options such as `limit` and OCR `force` from `commands.payload` and owns the Paperless/OCR/embedding work. Accepted review suggestions create durable `review_commit` commands and dispatch the review-commit actor wrapper with only the command id; Python loads `commands.payload.review_suggestion_id` before patching Paperless. The Laravel queued wrapper is allowlisted to these actor names and invokes the fixed runner module instead of arbitrary Python command strings.

## Database migrations

Run Laravel migrations before starting the event-driven pipeline:

```bash
cd laravel
php artisan migrate --force
```

The event-driven state tables are owned by PostgreSQL and include:

- `webhook_deliveries`
- `commands`
- `pipeline_runs`
- `pipeline_events`
- `actor_executions`
- `pipeline_items`
- `embedding_index_state`
- `llm_calls`
- `document_embeddings`

PostgreSQL is the source of truth for progress, retries, audit and recovery state. Laravel database queues are the enforced event-driven transport; queue payloads must remain small references to durable database rows. The queue connection is pinned to Laravel's application database so recovery can claim the source row and insert the job in one transaction. The six-hour actor timeout remains below the default `DB_QUEUE_RETRY_AFTER=21720` lease; keep that invariant if either setting changes so a long-running actor cannot be reclaimed concurrently.

The source-link migration cannot safely infer a command or webhook delivery for non-pipeline actor attempts created by older releases. It marks any such in-flight `running` or `retrying` execution `failed_permanent` with `error_type=source_link_unavailable_after_upgrade`. After upgrading, operators should inspect those rows and rerun the corresponding maintenance command or webhook from the operations UI only after confirming that the old actor process has stopped. The migration does not guess links or replay ambiguous work.

ADR-0015 supersedes the previous Absurd queue target, and ADR-0017 requires complete removal after parity migration. Current Absurd files are transitional inventory only; they must not receive new behavior. New work targets Laravel queues and fixed Python actor commands according to the hardening plan.

## Paperless webhook setup

Configure Paperless to send document events to:

```text
POST /api/webhooks/paperless
```

Webhook ingress requires a non-empty `PAPERLESS_WEBHOOK_SECRET` (or the encrypted global `webhook.secret`, which takes precedence). Paperless or the reverse proxy must send:

```text
X-Webhook-Secret: <secret>
```

Webhook ingestion is intentionally lightweight:

1. reject declared or actual bodies above `PAPERLESS_WEBHOOK_MAX_BYTES` (default 262144), validate the effective secret with constant-time comparison, and apply the shared per-client limiter for both aliases before entering the controller;
2. persist redacted raw and normalized delivery data in `webhook_deliveries`;
3. compute a dedupe key;
4. record pipeline events;
5. dispatch the appropriate Laravel queued actor job;
6. return quickly.

Do not perform OCR, embedding, classification, Paperless fetches or LLM calls inside the HTTP request.

## Worker startup

In the target container entrypoint, Laravel's queue worker consumes event-driven actor jobs:

```bash
cd laravel
php artisan queue:work --sleep=3 --tries=1
```

The supervised runtime uses Laravel as the exclusive queue, scheduler and recovery owner. Absurd compatibility code and schema remain only as cleanup debt; no Absurd worker or recovery bridge is started by Supervisor.

Useful manual commands:

```bash
# Run one embedding build command through the fixed actor-runner contract.
python -m app.actor_runner build-embedding-index --command-id=123

# One Laravel-native recovery scan and exit.
cd laravel
php artisan archibot:recovery-scan --limit=100

# Run the Laravel scheduler locally; the container supervises this command.
php artisan schedule:work

# Trigger the due-check manually without bypassing durable command creation.
php artisan archibot:scheduled-poll

# Run one persisted non-process webhook delivery through the fixed actor-runner contract.
python -m app.actor_runner handle-webhook --delivery-id=123
```

The recovery path scans durable PostgreSQL state and safely redispatches Laravel queued actor jobs. Actor executions link to their source command, pipeline run or webhook delivery so stale running and due retrying attempts can be recovered without global actor-name guesses. Laravel-native recovery finalizes safe cancellation requests, releases embedding-blocked work, suppresses fresh duplicate webhook dispatch, and handles pending/stale commands including entity approval sync. Process-document webhooks recover by starting or reconciling their durable pipeline runs rather than invoking the webhook actor; blocked deliveries become processed after the embedding gate releases and the linked run is queued.

## Embedding readiness gate

Document processing is blocked until the latest durable `embedding_index_state.status` is `complete`.

Allowed before the index is complete:

- webhook ingestion and dedupe;
- command creation;
- pending/blocked pipeline run creation;
- dashboard status viewing;
- starting or resuming the embedding build.

Blocked before completion:

- document fetch for processing;
- classification;
- review suggestion creation;
- webhook, poll, manual, retry or reindex-triggered document processing.

Admin dashboard controls:

- **Start embedding build** / **Resume embedding build** creates a durable `embedding_index_build` command for recovery pickup.
- **Mark embedding index stale** sets durable state to `stale`, closing the document-processing gate.
- **Start reindex** also marks the embedding index stale and creates a durable `reindex` command.

Recovery releases runs blocked by `embedding_index_not_ready` back to pending after the index becomes complete.

## Admin dashboard operations

The existing Laravel dashboard is the operations console. Job-control actions are visible to admins and protected by backend `is_admin` checks.

Available event-driven controls include:

- retry/cancel pipeline runs;
- force reprocess from review detail pages;
- retry webhook delivery;
- dismiss webhook failure;
- start/resume embedding build;
- mark embedding index stale;
- run poll now;
- start reindex;
- start OCR reindex;
- commit accepted event-driven review suggestions to Paperless.

Non-admin users must not be able to mutate job or pipeline execution state even if they bypass the UI.

## Recovery, retry and state

Durable state lives in PostgreSQL:

- webhook delivery status in `webhook_deliveries`;
- command status in `commands`;
- run status/progress/retry state in `pipeline_runs`;
- item-level progress in `pipeline_items`;
- actor execution status in `actor_executions`;
- audit/user-facing events in `pipeline_events`.

Recovery behavior:

- queued non-process webhook deliveries are redispatched to the webhook actor through Laravel queues;
- pending document runs are redispatched to the document actor through Laravel queues;
- due retrying document runs are redispatched after backoff;
- pending embedding-build, poll, reindex, OCR reindex, review-commit and entity-sync commands are redispatched through Laravel queues;
- stale `running` and due `retrying` actor executions are recovered through their linked durable source with bounded attempts;
- exhausted or unlinked actor retries fail permanently instead of looping forever;
- `cancel_requested` pipeline runs without a live actor are finalized as `cancelled`;
- embedding-blocked runs are released when the embedding index is complete;
- auto-accepted review suggestions create durable pending `review_commit` commands that Laravel recovery dispatches;
- pending embedding, poll, reindex and OCR reindex commands are bridged to actors.

Document actor retry classification uses bounded default backoff for retryable failures such as transient network/provider/Paperless errors, rate limiting and recoverable processing failures. Permanent validation or missing-document failures should not retry forever.

## Smoke checks

Local code-only checks used during the migration:

```bash
python3 scripts/event_driven_smoke.py
ruff format --check app/ tests/ scripts/event_driven_smoke.py
ruff check app/ tests/ scripts/event_driven_smoke.py
pytest tests/ -q

cd laravel
COMPOSER_ALLOW_SUPERUSER=1 composer test
npm run format:check
npm run types:check
```

Live integration smoke checklist:

1. Build/start the container stack.
2. Start PostgreSQL.
3. Run Laravel migrations against PostgreSQL.
4. Confirm `pgvector` extension is available.
5. Start Laravel, the Laravel queue worker, `schedule:work`, and Laravel-native recovery; verify no Absurd worker/recovery process starts.
6. Configure a non-empty secret on both sides, then point Paperless to `POST /api/webhooks/paperless` with `X-Webhook-Secret`. Missing configuration and missing/wrong headers fail closed without a Delivery. Use the rotation and rollback order in the [webhook runbook](../user/webhooks.md#secret-sicher-rotieren).
7. Send a test Paperless webhook for a document.
8. Verify a row exists in `webhook_deliveries`.
9. Run or wait for the Laravel queue worker to consume the queued actor job.
10. Verify `actor_executions` and `pipeline_events` rows are created.
11. Complete or start the embedding index and verify blocked work remains blocked until `embedding_index_state.status = complete`.
12. Verify a document run reaches `pipeline_runs`, `pipeline_items` and, after classification, `review_suggestions`.
13. Exercise admin retry/cancel/reprocess/webhook/reindex/poll controls from the dashboard.

## Security and redaction

- Do not expose the Paperless webhook endpoint publicly without network controls and a shared secret.
- Do not log API keys, auth tokens, webhook secrets, full OCR text, full document contents, full prompts or sensitive LLM responses.
- Store only minimal identifiers, hashes, statuses, durations and redacted error summaries in logs/events.
- Python workers do not decide user authorization; Laravel/API boundaries must enforce `is_admin` before creating job-control commands or mutating pipeline state.
- Existing Paperless storage paths are immutable; event-driven commit actors must only patch reviewed metadata IDs.

## Webhook endpoint note

The target endpoint is `/api/webhooks/paperless` with `/webhook` as the simple alias. Removed legacy webhook routes must not be extended or reintroduced for the new architecture. If downstream enqueue fails after a delivery is persisted, return a non-2xx response so Paperless retries.
