# Worker Jobs Release Readiness Review

Date: 2026-05-14

> Historical note: this readiness review covered the temporary `worker_jobs` stabilization phase. It is superseded by [ADR-0016](decisions/0016-clean-install-worker-jobs-retirement.md) and the durable job-control model in [docs/architecture/job-control-model.md](architecture/job-control-model.md). `worker_jobs` has since been retired for clean installs.

## Scope

This review stabilizes the current Laravel `worker_jobs` control plane and the operational parity sweep before Phase 13/event-driven migration. It intentionally does not start the Absurd actor migration and does not add feature behavior.

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
- `docs/laravel-gui-parity.md` was refreshed for the completed Webhook Deliveries UI, Errors improvements, Stats parity work, MCP settings coverage, and the current maintenance-reset caveat.
- No broken Markdown links were found after renumbering and parity updates.
- Worker job dispatch, leases, heartbeats, recovery, cancellation, result idempotency, operator controls, health checks and operational UI remain scoped to the temporary Laravel control plane described in ADR-0012.

## Operational risks to keep visible

- Maintenance reset is exposed only to authenticated admins with an explicit `RESET` confirmation. The UI and `archibot reset --yes` both use the same Laravel/PostgreSQL reset service and record the real authenticated user or explicit `local_operator` principal.
- Some Svelte navigation still uses hardcoded internal paths instead of generated route helpers. This is low-risk for the default deployment, but can matter for deployments using `archibot.path_prefix`.
- Historical only: the Python `archibot jobs` CLI noted here has since been removed. Operator-facing CLI actions that overlap GUI actions now delegate to Laravel Maintenance and durable commands/pipeline runs.

## Remaining partial parity items

From `docs/laravel-gui-parity.md`, the remaining `partial` areas are:

- Dashboard: deeper phase-level historical analytics and deployment-specific checks beyond lightweight `/healthz` probes.
- Inbox: local poll/reprocess controls are not directly embedded in the Inbox page; controls live in Dashboard/Maintenance.
- Review Detail: raw/original debugging snapshots may need fuller exposure if operators still rely on them.
- Embeddings: page reads PostgreSQL/pgvector metadata and durable command/pipeline state; the legacy Python SQLite metadata seam was removed in hardening Step 10.
- Stats: detailed phase timing/error-rate analytics remain deferred to pipeline events or a later explicit decision.
- Errors: acknowledgement/assignment/export workflow is not implemented; current page focuses on diagnostics and existing retry/dismiss navigation.
- Chat/RAG is disabled for every user; historical chat rows remain stored but are not exposed. Issue #221 is the only redesign/re-enable track.
- Settings: deployment-only settings remain intentionally outside Laravel settings.
- Audit Logs: latest-100 view lacks filters/export/detail view.
- Maintenance: destructive reset has CLI/UI parity through one Laravel service; browser use is admin-only and confirmation-gated.
- MCP: no MCP server status/health page, per-token permissions UI, or direct rate-limit/write-tool status page.

## Readiness summary

Historical conclusion at the time: the temporary Laravel `worker_jobs` control plane was stable enough to enter Phase 13 planning. Current state: `worker_jobs` has been retired, Operations Log and Pipeline Runs provide durable visibility, Maintenance is the admin action-launch surface, and destructive reset is shared by the confirmed admin UI and local operator CLI.
