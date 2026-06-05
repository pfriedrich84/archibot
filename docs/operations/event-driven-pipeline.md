# Event-driven Pipeline Operations

This guide covers the new Archibot event-driven processing path. It is the target path for Paperless webhooks, polling reconciliation, embedding builds, reprocess, retry, reindex and review commits.

## Required services and environment

Required runtime services:

- PostgreSQL with `pgvector` enabled.
- PostgreSQL-backed Absurd queue.
- Laravel HTTP app and queue worker.
- Event recovery bridge.
- Paperless and the configured LLM/embedding provider.

Core environment variables:

```env
DATABASE_URL=postgresql+psycopg://archibot:archibot@postgres:5432/archibot
ABSURD_DATABASE_URL=postgresql+psycopg://archibot:archibot@postgres:5432/archibot
ARCHIBOT_QUEUE_PREFIX=archibot
POLL_INTERVAL_SECONDS=600
PAPERLESS_WEBHOOK_SECRET=<generate-a-random-secret>
```

Optional direct webhook enqueue bridge:

```env
ARCHIBOT_WEBHOOK_DIRECT_ENQUEUE=true
ARCHIBOT_PYTHON_BINARY=python
```

When direct enqueue is enabled, Laravel does not execute arbitrary configured argv. It runs the fixed safe command with the configured Python binary:

```bash
python -m app.event_worker enqueue-webhook --delivery-id <webhook_delivery_id>
```

If direct enqueue is disabled or fails, the webhook delivery stays durable in PostgreSQL and the recovery bridge can enqueue it later.

Initial queues use the configured prefix:

```text
archibot.webhook
archibot.io
archibot.llm
archibot.embedding
archibot.blocking
```

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

PostgreSQL is the source of truth for progress, retries, audit and recovery state. Absurd is the only queue transport for the event-driven path.

The Absurd PostgreSQL schema is vendored at `laravel/database/sql/absurd.sql` from upstream Absurd `main` as of the 0.4.0 Python SDK integration. Keep that SQL file and the pinned `absurd-sdk==0.4.0` dependency in sync when upgrading Absurd.

## Paperless webhook setup

Configure Paperless to send document events to:

```text
POST /api/webhooks/paperless
```

If `PAPERLESS_WEBHOOK_SECRET` is configured, Paperless or the reverse proxy must send:

```text
X-Webhook-Secret: <secret>
```

Webhook ingestion is intentionally lightweight:

1. validate the optional secret and payload shape;
2. persist raw and normalized delivery data in `webhook_deliveries`;
3. compute a dedupe key;
4. record pipeline events;
5. optionally attempt fixed direct enqueue;
6. return quickly.

Do not perform OCR, embedding, classification, Paperless fetches or LLM calls inside the HTTP request.

## Worker startup

In the container entrypoint, the Absurd worker is started when `ABSURD_DATABASE_URL` or `DATABASE_URL` is configured:

```bash
python -m app.event_worker start-workers
python -m app.event_worker recovery-scan --interval-seconds "${EVENT_RECOVERY_INTERVAL_SECONDS:-30}"
```

Useful manual commands:

```bash
# One recovery scan and exit.
python -m app.event_worker recovery-scan --once

# Continuous recovery and polling loop.
python -m app.event_worker recovery-scan --interval-seconds 30

# Enqueue one persisted webhook delivery, used by ARCHIBOT_WEBHOOK_DIRECT_ENQUEUE.
python -m app.event_worker enqueue-webhook --delivery-id=123
```

The recovery bridge scans durable PostgreSQL state and safely requeues work such as queued webhooks, pending document runs, due retrying runs, accepted review commits, embedding builds, poll commands and reindex commands.

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

- queued webhook deliveries are enqueued to the webhook actor;
- pending document runs are enqueued to the document actor;
- due retrying document runs are requeued after backoff;
- stale `running` actor executions are marked `retrying` with `retry_mode=recovery`;
- `cancel_requested` pipeline runs are finalized as `cancelled`;
- embedding-blocked runs are released when the embedding index is complete;
- accepted review suggestions are enqueued for Paperless commit;
- pending embedding, poll and reindex commands are bridged to actors.

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
5. Start Laravel, Laravel queue worker, Absurd actors and the recovery bridge.
6. Configure Paperless webhook to `POST /api/webhooks/paperless` with `X-Webhook-Secret` if configured.
7. Send a test Paperless webhook for a document.
8. Verify a row exists in `webhook_deliveries`.
9. Run or wait for recovery/direct enqueue.
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
