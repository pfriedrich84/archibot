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

Laravel writes stable Temporal workflow starts and signals to a transactional outbox. The supervised Python relay delivers those intents, and Temporal workflows run idempotent Python activities that project business state and events to PostgreSQL. Laravel queued actors remain temporarily for OCR reindex and non-process webhook actions only.

Fixed actor-runner contracts:

```bash
python -m app.actor_runner reindex-ocr --command-id <commands.id>
python -m app.actor_runner handle-webhook --delivery-id <webhook_deliveries.id>
```

Laravel queued wrappers:

```php
RunPythonActorJob::reindexOcr($commandId)
RunPythonActorJob::webhookDelivery($deliveryId)
```

Embedding builds, full reindex, poll discovery, document processing and review commits use stable Temporal workflow IDs. Accepted current reviews signal the waiting document workflow; accepted pre-cutover reviews start a standalone `ReviewCommitWorkflow`. ADR-0018 containment remains in force: model and judge output only creates pending Review Suggestions. OCR reindex and non-process webhook actions still use the fixed actor contracts above until Phase 5 retirement.

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

PostgreSQL is the source of truth for business state, progress projections and audit. Temporal history is authoritative for workflow execution, retries, timers and recovery. The transactional outbox contains only stable IDs and bounded workflow payloads; secrets and document content are loaded inside activities. Laravel queue settings still apply to the remaining migration-only actors.

The source-link migration cannot safely infer a command or webhook delivery for non-pipeline actor attempts created by older releases. It marks any such in-flight `running` or `retrying` execution `failed_permanent` with `error_type=source_link_unavailable_after_upgrade`. After upgrading, operators should inspect those rows and rerun the corresponding maintenance command or webhook from the operations UI only after confirming that the old actor process has stopped. The migration does not guess links or replay ambiguous work.

ADR-0022 governs the target transport: Temporal is the sole owner of migrated productive flows. The previous Python queue backend has been removed, and the bounded Laravel actor transport is retired one flow at a time. Existing historical schema objects on upgraded volumes are inert; see the [retired transport upgrade notes](../implementation-notes/absurd-removal.md).

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
5. write the appropriate Temporal outbox intent or remaining legacy actor job;
6. return quickly.

Do not perform OCR, embedding, classification, Paperless fetches or LLM calls inside the HTTP request.

## Worker startup

The container supervisor starts the Temporal worker and transactional outbox relay:

```bash
python -m app.temporal.worker
python -m app.temporal.relay
```

Laravel's scheduler remains active for discovery timing, and its queue worker serves only migration flows that have not yet moved to Temporal. Laravel recovery excludes all Temporal-owned commands and runs.

Useful manual commands:

```bash
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

Temporal recovers migrated workflows from durable history and heartbeat state. Laravel recovery scans only remaining legacy-owned rows; it cannot reclaim commands or runs whose orchestration driver is `temporal`. Entity approval decisions retain their queued, fenced Laravel/PostgreSQL command seam during the bounded migration.

## Embedding readiness gate

Document processing is blocked until the durable `embedding_index_state` for the
currently configured embedding model has `status = complete`. A completed generation
for another model does not open the gate. Installations that predate model-aware state
use the latest row only until a configured model is available.

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

Gate-closed discoveries reserve the stable document workflow identity but do not start
the Temporal `DocumentWorkflow`. Recovery releases them after the matching model index
becomes complete. An empty index build reaches `0/0 complete` immediately and opens the
same gate without calling the provider.

After release, the singleton Temporal model-phase scheduler drains the current work set
in this order: embedding, configured OCR, classification, judge, review release. Work
arriving after the embedding boundary waits for the next cycle. Fixed model-specific
task queues allow concurrency within a phase while preventing requests for another role
from causing a model swap.

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
- Temporal-owned embedding, poll, document and review-commit work is never redispatched by Laravel recovery;
- pending OCR reindex commands and queued non-process webhook deliveries remain recoverable through the bounded Laravel actor transport;
- entity approval application remains a queued, PostgreSQL-owned Laravel command with its allowlisted `ApplyEntityApprovalCommand`;
- stale `running` and due `retrying` actor executions are recovered through their linked durable source with bounded attempts;
- exhausted or unlinked actor retries fail permanently instead of looping forever;
- `cancel_requested` pipeline runs without a live actor are finalized as `cancelled`;
- legacy embedding-blocked rows can be reconciled after the embedding index is complete;
- authorized review decisions create a transactional Temporal signal or workflow-start intent;
- an upgrade migration adopts accepted queued/running legacy review commits into Temporal.

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
5. Start Laravel, the Temporal worker and relay, `schedule:work`, and the bounded Laravel queue/recovery processes; verify the Temporal namespace and task queue are ready.
6. Configure a non-empty secret on both sides, then point Paperless to `POST /api/webhooks/paperless` with `X-Webhook-Secret`. Missing configuration and missing/wrong headers fail closed without a Delivery. Use the rotation and rollback order in the [webhook runbook](../user/webhooks.md#secret-sicher-rotieren).
7. Send a test Paperless webhook for a document.
8. Verify a row exists in `webhook_deliveries`.
9. Run or wait for the Temporal relay and worker to consume the outbox intent.
10. Verify the Temporal workflow ID, `pipeline_runs` projection and `pipeline_events` rows are created.
11. Complete or start the embedding index and verify blocked work remains blocked until `embedding_index_state.status = complete`.
12. Verify a document run reaches `pipeline_runs`, `pipeline_items` and, after classification, `review_suggestions`.
13. Accept and reject reviews; verify acceptance commits once through Temporal, rejection performs no PATCH, and the current Paperless storage path is shown and preserved.
14. Exercise admin retry/cancel/reprocess/webhook/reindex/poll controls from the dashboard.

## Security and redaction

- Do not expose the Paperless webhook endpoint publicly without network controls and a shared secret.
- Do not log API keys, auth tokens, webhook secrets, full OCR text, full document contents, full prompts or sensitive LLM responses.
- Store only minimal identifiers, hashes, statuses, durations and redacted error summaries in logs/events.
- Python workers do not decide user authorization; Laravel/API boundaries must enforce `is_admin` before creating job-control commands or mutating pipeline state.
- Existing Paperless storage paths are immutable; event-driven commit actors must only patch reviewed metadata IDs.

## Webhook endpoint note

The target endpoint is `/api/webhooks/paperless` with `/webhook` as the simple alias. Removed legacy webhook routes must not be extended or reintroduced for the new architecture. If downstream enqueue fails after a delivery is persisted, return a non-2xx response so Paperless retries.
