# Event-driven Migration Review Checklist

Use this checklist for every migration change.

## Architecture invariants

- Paperless webhooks are the primary trigger.
- Polling remains automatic every 600 seconds as reconciliation/fallback.
- Webhooks and polling use the same pipeline-start/dedupe/lock logic.
- No document processing starts before `embedding_index_state.status = complete`.
- PostgreSQL is the durable source of truth for progress, retries and audit state.
- Absurd is execution transport, not the only job state.
- Actors are idempotent and retry-safe.
- The existing Laravel dashboard is extended, not replaced.
- No new legacy compatibility mode is introduced.

## Webhook ingestion

- Webhook request validates configured security headers/secrets.
- Raw and normalized payloads are persisted before enqueueing.
- Dedupe key is stable and enforced.
- The HTTP request does no OCR, embedding, classification, LLM or heavy Paperless work.
- Absurd/enqueue failure does not lose the persisted delivery.

## Durable progress/retry

- Progress is stored in PostgreSQL fields and/or item state.
- Progress is not derived from logs or in-memory counters.
- Retry cannot duplicate outputs or double-count progress.
- Blocking states are not treated as processing failures.
- Recovery can safely requeue stuck retryable work.

## Authorization/UI

- Every job-control endpoint checks `is_admin()` before mutation.
- Admin-only job controls have explicit Laravel UI buttons/actions.
- Non-admin UI controls are hidden/disabled, and direct calls return `403`.
- Python workers do not decide user authorization.

## Observability and safety

- Pipeline events are persisted for user-facing/audit state.
- Structured logs include relevant correlation IDs.
- Secrets, full OCR text, full document contents and full LLM prompts are not logged.
- New or changed trust boundaries are documented in `docs/governance/trust-boundaries.md`.
- Release, rollback, migration or provenance impact is documented when relevant.

## Validation

- Targeted tests or smoke checks cover changed behavior.
- Migrations run on supported local drivers or clearly guard database-specific features.
- The app remains runnable after the milestone.
