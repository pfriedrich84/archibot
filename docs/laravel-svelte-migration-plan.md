# Laravel + Svelte migration completion record

This document originally held the implementation roadmap for replacing ArchiBot's previous Python web application layer with Laravel + Inertia/Svelte while keeping Python for workers, AI/Ollama classification, Paperless execution, embeddings, Telegram runtime, and MCP runtime.

The migration is complete on `main`.

## Final recommendation

Laravel/Svelte is the primary web UI/API and owns application-layer concerns: setup, Paperless-backed login, sessions, authorization, settings, audit logs, review queue state, entity approvals, worker job orchestration, dashboard/status pages, inbox reads, document preview proxying, and MCP token management.

Python remains the execution/runtime layer for document classification, OCR assistance, embeddings/vector search, Paperless mutation execution, CLI worker commands, Telegram runtime, and MCP tools. The boundary is intentionally narrow: Laravel queues `worker_jobs`; the Laravel queue worker invokes Python CLI commands with JSON input/output files.

## Current architecture

- `laravel/` contains the primary Laravel 13 + Inertia/Svelte application.
- `entrypoint.sh` prepares `/data/laravel/database.sqlite`, persists the Laravel `APP_KEY`, runs migrations, starts the Laravel database queue worker, optionally starts the Python MCP server, and serves Laravel on `${GUI_PORT:-8088}`.
- Docker Compose runs one combined ArchiBot container for Laravel/Svelte plus Python worker/MCP runtime.
- `/data/laravel/database.sqlite` stores Laravel-owned app state: users, encrypted Paperless tokens, settings, setup state, audit logs, review suggestions, entity approvals, MCP tokens, and worker jobs.
- The Python database remains worker-owned for embeddings, OCR cache, idempotency, poll/reindex data, and compatibility records.

## Laravel owns

- First-run setup wizard and reset-token flow.
- Paperless username/password login using Paperless `/api/token/` and profile/admin refresh through Paperless UI/profile endpoints.
- Laravel sessions and Paperless-admin-to-ArchiBot-admin mapping.
- Admin settings with masked/write-only secrets and one-time legacy config import.
- Audit logs and audit pruning.
- Review suggestion queue and review accept/reject state.
- Worker job records and queue orchestration.
- Inbox, dashboard/status, review detail, and Paperless preview proxy pages.
- Entity approval UI/state for tags, correspondents, and document types.
- Per-user MCP token lifecycle and verifier command for the Python MCP runtime.

## Python stays

- AI/Ollama classification and judge logic.
- OCR correction helpers.
- Embedding generation, sqlite-vec/FTS hybrid search, and reindex execution.
- Paperless mutation execution used by worker commands.
- Telegram runtime for now.
- MCP runtime/tools, authenticated through Laravel-issued tokens when enabled.
- CLI commands used by Laravel jobs: `poll`, `reindex`, `process-document`, `commit-review`, and `sync-entity-approval`.

## Auth, permissions, and tokens

- GUI users authenticate with Paperless-NGX username/password, not independent local Laravel passwords.
- Laravel stores each user's Paperless token encrypted with the Laravel app key.
- ArchiBot admin status is refreshed from Paperless superuser/admin data on login.
- Document preview/access paths use the current user's Paperless token and fail closed when Paperless is unavailable.
- MCP tokens are user-managed, nameable, shown once, stored hashed, revocable, audited, and verified by `php artisan archibot:mcp-token-verify`.
- MCP write tools remain globally controlled by `MCP_ENABLE_WRITE`.

## Laravel-to-Python boundary

Laravel uses SQLite database queues and `worker_jobs` records. A queued Laravel job writes structured JSON input, invokes Python from the repository root, and stores JSON output/result metadata back on the `worker_jobs` row.

The Python side emits stable JSON result shapes. Review-producing worker output is ingested into Laravel `review_suggestions`; entity decisions and review commits are mirrored back through dedicated Python CLI commands where worker/Paperless compatibility is still needed.

## Docker and CI/GHCR

- The combined image is published to GHCR from `main` as `latest` and `sha-<short>` after CI passes.
- The switch-over image for commit `22ce7bb` was published successfully as `ghcr.io/pfriedrich84/archibot:latest` and `ghcr.io/pfriedrich84/archibot:sha-22ce7bb`.
- Later documentation cleanup was pushed as `3ea956a` and `2c364d8`.
- Docker startup validation could not be run in the coding harness because Docker was unavailable there; GitHub Actions build/publish completed successfully.

## Completed migration milestones

- Created and pushed `feat/laravel`.
- Added the official Laravel Svelte starter under `laravel/`.
- Added Laravel CI for Composer/Pest and Svelte lint/format/type/build checks.
- Added Laravel first-run setup, Paperless-backed auth, setup guard, and CLI setup reset.
- Removed local Laravel account-management surfaces from the starter.
- Added admin settings, secret masking, audit logs, one-time legacy settings import, and audit pruning.
- Added Laravel-owned review queue state and pages.
- Added Laravel `worker_jobs` and the Python JSON CLI worker contract.
- Added worker result ingestion into Laravel review suggestions.
- Added Python review suggestion emission for `process-document` and `poll`.
- Added review accept commit boundary through Python and commit status tracking.
- Added Laravel Paperless document preview proxy.
- Added Laravel inbox and dashboard/status pages.
- Added entity approval foundations and Python sync boundary.
- Added Laravel MCP token management, verifier command, Python MCP verifier integration, verified MCP identity context, and scoped Paperless access in MCP tools.
- Published the feature image and then switched `main` to Laravel/Svelte as the primary runtime.
- Removed the old standalone frontend and legacy FastAPI frontend/proxy routes.
- Published the `main`/`latest` image after CI passed.

## Remaining follow-up work

The migration umbrella is closed. Future work should be tracked as smaller feature TODOs, for example:

- Per-user Telegram token/linking UI and runtime polish.
- Additional dashboard/processing/status page polish.
- Deeper cleanup or mechanical relocation of Python worker code if desired.
- End-to-end deployment smoke tests in an environment with Docker available.
- German/localized UI copy cleanup if wanted.
