# Worker Jobs Release Readiness Review

Date: 2026-05-14

## Scope

This review stabilizes the current Laravel `worker_jobs` control plane and the operational parity sweep before Phase 13/event-driven migration. It intentionally does not start RabbitMQ/Dramatiq migration and does not add feature behavior.

## Validation

All requested checks passed on `main` after the documentation cleanup:

- `cd laravel && composer test` — passed (`195` tests)
- `cd laravel && npm run lint:check` — passed
- `cd laravel && npm run format:check` — passed
- `cd laravel && npm run types:check` — passed
- `cd laravel && npm run build` — passed
- `python3 scripts/check_markdown_links.py` — passed
- `git diff --check` — passed

## Stabilization findings

- Duplicate ADR numbering was corrected safely: `worker_jobs` temporary-control-plane ADR is now `ADR-0012` at `docs/decisions/0012-worker-jobs-as-temporary-control-plane.md`; the existing embedding-readiness ADR remains `ADR-0006`.
- `docs/laravel-gui-parity.md` was refreshed for the completed Webhook Deliveries UI, Errors improvements, Stats parity work, MCP/Telegram settings coverage, and the current maintenance-reset caveat.
- No broken Markdown links were found after renumbering and parity updates.
- Worker job dispatch, leases, heartbeats, recovery, cancellation, result idempotency, operator controls, health checks and operational UI remain scoped to the temporary Laravel control plane described in ADR-0012.

## Operational risks to keep visible

- Maintenance reset is no longer exposed in the Laravel GUI. Destructive reset is CLI-only via `archibot reset --yes`, which delegates to `php artisan archibot:reset --yes` and clears Laravel/PostgreSQL worker/job-control, pipeline, embedding, audit, chat, session/cache, webhook, review, OCR and entity-approval state.
- Some Svelte navigation still uses hardcoded internal paths instead of generated route helpers. This is low-risk for the default deployment, but can matter for deployments using `archibot.path_prefix`.
- The Python `archibot jobs` CLI still inspects job state through legacy assumptions; Laravel remains the canonical operator surface for job control.

## Remaining partial parity items

From `docs/laravel-gui-parity.md`, the remaining `partial` areas are:

- Dashboard: deeper phase-level historical analytics and deployment-specific checks beyond lightweight `/healthz` probes.
- Inbox: local poll/reprocess controls are not directly embedded in the Inbox page; controls live in Worker Jobs/Maintenance.
- Review Detail: raw/original debugging snapshots may need fuller exposure if operators still rely on them.
- Embeddings: page still reads legacy Python SQLite metadata; final state should use PostgreSQL/pgvector and pipeline-run state.
- Stats: detailed phase timing/error-rate analytics remain deferred to pipeline events or a later explicit decision.
- Errors: acknowledgement/assignment/export workflow is not implemented; current page focuses on diagnostics and existing retry/dismiss navigation.
- Chat: recent activity remains empty compared with legacy audit-derived activity.
- Settings: deployment-only settings remain intentionally outside Laravel settings.
- Audit Logs: latest-100 view lacks filters/export/detail view.
- Maintenance: destructive reset is intentionally excluded from GUI parity and remains operator-only CLI functionality.
- MCP: no MCP server status/health page, per-token permissions UI, or direct rate-limit/write-tool status page.
- Telegram: no connection status, test notification, or Telegram session-management UI.

## Readiness summary

The current Laravel `worker_jobs` control plane is stable enough to enter Phase 13 planning, provided the event-driven migration continues to treat `worker_jobs` as temporary and destructive reset remains a deliberate operator CLI action outside the GUI.
