# Agent Rules

Core rules for coding agents working on ArchiBot.

## Product safety

- Keep ArchiBot single-container and Docker-first.
- Do not overwrite existing Paperless storage paths.
- Keep manual review as the default safety path, while preserving existing configured `auto_commit_confidence` behavior in event-driven processing.
- Do not use inbox/unreviewed documents as trusted classification context. A document is trusted for classification context only when it does not have the configured inbox tag.
- Do not create new Paperless tags, correspondents, or document types outside the approval/whitelist flow.
- Non-admin users may accept, reject, or otherwise work on suggestions only when they have the right to change the corresponding Paperless document.
- Keep OCR corrections local; never write corrected OCR text back to Paperless content.
- In the GUI, never show raw Paperless tag/entity IDs by themselves (for example, do not show only `124` or `Tag #124`). Numeric IDs may be used internally, but user-facing screens must resolve them to the Paperless label/name and, when the ID is useful for disambiguation, display `Label (#ID)` such as `Posteingang (#124)`.
- The GUI must never show raw JSON to display metadata; present metadata with user-friendly labels, fields, tables, badges, or structured UI components instead.
- Date format and timezone must be configured via `.env` and used by both the Laravel/Svelte GUI and the Python worker/CLI. The default timezone is `Europe/Vienna`.
- CLI and UI must always execute the same product behavior. Any command available through the CLI must use the same backend, configuration source, durable state, progress semantics, database/storage target, authorization assumptions, and side effects as the corresponding Laravel UI action. Never leave CLI commands on a legacy path when the UI has migrated.
- Removed legacy Paperless webhook endpoints must not be reintroduced or extended. Keep `/api/webhooks/paperless` and `/webhook` as the durable event-driven webhook surfaces.
- After a webhook delivery is durably persisted, downstream enqueue failure should return non-2xx so Paperless retries.
- Reset is PostgreSQL/Laravel-owned. Keep `archibot reset` as the operator-facing CLI command, but it must delegate to `php artisan archibot:reset` and must not silently reset only the legacy Python SQLite database.
- Job control, behavior, and status semantics must be identical in the CLI and GUI. If a job is no longer running according to the CLI, it must not be shown as running in the GUI, especially after restarts or reboots.
- Degrade gracefully: if OCR, embeddings, judge, Telegram, or optional integrations fail, continue where safe and surface the error for review.

## Change discipline

- Prefer small, reviewable changes.
- Update docs when behavior changes.
- Run relevant checks before finishing code changes; see [`CHECKS.md`](CHECKS.md).
- Include regression tests for code changes to ensure existing functions, features, and pages are not broken.
- For dependency changes, enforce the 3-day supply-chain age check.
- For Docker/runtime image changes, run a local build and Grype scan when available.
- Do not expose or modify secrets from `.env`.

## Domain invariants

- ArchiBot suggests metadata first; Paperless updates happen only after explicit approval or configured safe automation.
- Explicit user-selected force reprocess always creates a new pipeline run, even for identical content.
- A Paperless storage path that already exists on a document is authoritative.
- Review queues and whitelists are safety boundaries, not implementation details.
- Python owns document processing, embeddings, AI-provider calls, and MCP runtime; Laravel/Svelte owns UI, setup, settings, review, and worker-job orchestration.
