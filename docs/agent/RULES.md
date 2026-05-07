# Agent Rules

Core rules for coding agents working on ArchiBot.

## Product safety

- Keep ArchiBot single-container and Docker-first.
- Do not overwrite existing Paperless storage paths.
- Keep manual review as the default safety path.
- Do not use inbox/unreviewed documents as trusted classification context.
- Do not create new Paperless tags, correspondents, or document types outside the approval/whitelist flow.
- Keep OCR corrections local; never write corrected OCR text back to Paperless content.
- In the GUI, always show Paperless labels/names instead of raw numeric IDs (for example, show `Posteingang` instead of `124`). Numeric IDs may be used internally, but user-facing screens must resolve them to labels.
- The GUI must never show raw JSON to display metadata; present metadata with user-friendly labels, fields, tables, badges, or structured UI components instead.
- Date format and timezone must be configured via `.env` and used by both the Laravel/Svelte GUI and the Python worker/CLI. The default timezone is `Europe/Vienna`.
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
- A Paperless storage path that already exists on a document is authoritative.
- Review queues and whitelists are safety boundaries, not implementation details.
- Python owns document processing, embeddings, Ollama calls, and MCP runtime; Laravel/Svelte owns UI, setup, settings, review, and worker-job orchestration.
