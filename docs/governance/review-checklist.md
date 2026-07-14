# Event-driven Migration Review Checklist

Use this checklist for every migration change.

## Architecture invariants

- Paperless webhooks are the primary trigger.
- Polling remains automatic every 600 seconds as reconciliation/fallback.
- Webhooks and polling use the same pipeline-start/dedupe/lock logic.
- No document processing starts before `embedding_index_state.status = complete`.
- PostgreSQL is the durable source of truth for progress, retries and audit state.
- Laravel database queues are execution transport; PostgreSQL pipeline tables remain the durable source of truth.
- Actors are idempotent and retry-safe.
- The existing Laravel dashboard is extended, not replaced.
- No new legacy compatibility mode is introduced.

## Webhook ingestion

- Webhook request validates configured security headers/secrets.
- Raw and normalized payloads are persisted before enqueueing.
- Dedupe key is stable and enforced.
- The HTTP request does no OCR, embedding, classification, LLM or heavy Paperless work.
- Laravel queue dispatch/enqueue failure does not lose the persisted delivery and returns non-2xx when Paperless should retry.

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

## Validation and evidence

- Targeted tests or smoke checks cover changed behavior.
- Validation and review use the identity, result states, truncation and freshness rules in [`../agent/CONTEXT_AND_EVIDENCE.md`](../agent/CONTEXT_AND_EVIDENCE.md); exit code zero alone is not approval.
- Required delegated scopes and findings are complete and reconciled; missing coverage is `INCONCLUSIVE`.
- Evidence is current for the final patch; affected earlier checks or reviews were marked `STALE` and rerun.
- Migrations run on supported local drivers or clearly guard database-specific features.
- The app remains runnable after the milestone.
