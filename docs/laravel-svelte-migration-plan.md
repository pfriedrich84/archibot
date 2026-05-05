# Laravel + Svelte migration implementation roadmap

## Recommendation

Move ArchiBot's normal application UI/API from FastAPI to Laravel + the official Laravel Svelte starter kit on `feat/laravel`, while keeping Python as the execution runtime for document classification, Paperless integration work, embeddings/vector search, OCR assistance, scheduled/background jobs, Telegram command execution, and the existing MCP runtime until each piece has a proven Laravel replacement. The desired long-term endpoint is: no FastAPI server is required for the normal application UI/API.

This gives ArchiBot a clearer application boundary, Laravel session/auth conventions, first-class authorization policies, encrypted secret storage, database migrations, queues, jobs, and feature tests. The trade-off is a temporary two-runtime repository and Docker image; keep that simple by using SQLite/database queues, one `/data` volume, and a CLI-oriented Laravel-to-Python boundary before considering additional services.

Branch status: `feat/laravel` has been created locally for this work. The branch still needs to be pushed with `git push -u origin feat/laravel` from an environment with GitHub credentials. CI image publishing has been changed to run only after the `CI` workflow succeeds on `main` or `feat/laravel`, publishing `latest` for `main`, `feat-laravel` for `feat/laravel`, and `sha-<short>` tags.

## Current architecture

- `app/main.py` creates the FastAPI app, initializes SQLite, starts shared Paperless/Ollama/Telegram clients, starts APScheduler, mounts static Svelte build output, adds CSRF/security/CORS/basic-auth middleware, and registers routers.
- `app/routes/api.py` is the main JSON API consumed by the Svelte frontend. It exposes dashboard/status/errors, review queue/detail/mutations, inbox, entity approvals, jobs/reindex, stats, embeddings, chat, settings, Paperless/Ollama test endpoints, and prompt editing.
- `app/routes/webhook.py` handles Paperless webhook events for new/changed documents, guarded only by the optional webhook secret and exempt from GUI auth/CSRF.
- `app/worker.py`, `app/indexer.py`, and `app/pipeline/*` implement scheduled/manual polling, reindexing, OCR correction, embeddings, similar-document retrieval, LLM classification/judging, suggestion persistence, Paperless PATCH commits, and retroactive entity application.
- `app/db.py` owns the current SQLite schema under `DATA_DIR`, including review suggestions, processed document status, entity whitelists/blacklists, errors, audit_log, app_state, poll/job event tables, sqlite-vec embeddings, FTS, and OCR cache.
- `app/config.py` and `app/config_writer.py` load settings from environment plus `/data/config.env`; the settings API writes that file and hot-reloads clients/scheduler where possible.
- Auth is currently weak for the application layer: if `GUI_USERNAME`/`GUI_PASSWORD` are unset, UI/API routes are effectively unauthenticated. Basic auth is global, not per Paperless user. CSRF is double-submit cookie/header for unsafe routes.
- Current API surface to replace includes `/api/v1/*`, legacy page redirects (`/review`, `/settings`, etc.), `/healthz`, and eventually webhook routing. Webhooks may temporarily continue to call Python until Laravel owns orchestration.

## Current frontend feature checklist

Before switch-over, the Laravel Svelte UI must preserve these behaviors, regardless of visual redesign:

- Global app shell/navigation from `frontend/src/lib/nav.ts` with dashboard, review, inbox, processing, tags, correspondents, doctypes, chat, embeddings, stats, errors, settings, setup, and sign-in routes.
- Dashboard (`/`): status cards, pipeline/reindex state, recent errors, manual poll actions, poll-all action, cancel action, and navigation badges.
- Processing (`/processing`): current and recent job events, progress/error state, poll/reindex visibility, start/cancel controls where present.
- Review queue (`/review`): list pending suggestions, filters/search/pagination behavior if present, confidence badges, suggested vs original field diffs, preview links, edit/save, accept, reject, bulk accept, bulk reject, and error feedback.
- Document previews: suggestion preview and direct document preview must stream/proxy from Paperless while enforcing user permissions.
- Inbox (`/inbox`): list Paperless inbox documents and show current classification/review status where available.
- Entity approval pages (`/tags`, `/correspondents`, `/doctypes`): pending/approved/rejected/blacklisted state, approve, reject, unblacklist, retroactive application messages, and empty/loading/error states.
- Chat (`/chat`): load sessions, ask questions over indexed document context, show answers/citations/source documents, reload sessions, delete sessions, and show unavailable states for missing embeddings/Ollama/Paperless.
- Embeddings (`/embeddings`): show embedding/index status, counts, model/dimension information, and reindex controls/status.
- Stats (`/stats`): show processing/review/entity/classification statistics matching current API payload intent.
- Errors (`/errors`): show recent errors with phase/document context and retry/remediation affordances that currently exist.
- Settings (`/settings`): dynamic settings schema, grouped admin settings, masked secrets, Paperless/Ollama connection tests, Paperless tag option loading, prompt editor load/save/reset, restart-required indications, field validation errors, and successful save feedback.
- Setup (`/setup`): initial required configuration flow for Paperless/Ollama/tag IDs and setup-complete transition, replaced by the new Paperless-admin-verified wizard.
- Sign-in (`/auth/sign-in`): becomes real Paperless-backed login; current placeholder route must be replaced.
- Shared UI behavior: German labels can remain initially, loading skeletons, empty states, status panels, stat cards, confidence badges, diff rendering, CSRF/session-safe mutations, API error display, and existing Vitest/Playwright smoke coverage.

## Target Laravel/Svelte architecture

Create `laravel/` with the latest official Laravel Svelte starter kit available at implementation time. Laravel owns HTTP routing, sessions, auth, authorization, app state, settings, audit logs, review workflow state, UI shell, and API orchestration. Svelte pages should use the starter kit conventions (Inertia/Svelte if that is the official starter at implementation time) and gradually port reusable UI ideas from `frontend/src`.

Laravel should expose new internal application endpoints as needed; exact FastAPI path compatibility is not mandatory, but each current frontend behavior above must work before switch-over.

## Repository layout

Recommended target layout during migration:

```text
app/                         # existing Python runtime until switch-over
frontend/                    # existing Svelte app until replaced
laravel/                     # new Laravel + Svelte starter app
workers/                     # later home for Python runtime, after safe move
prompts/                     # shared by Python workers; later mount/copy into image
docs/laravel-svelte-migration-plan.md
Dockerfile                   # evolves into combined Laravel + Python image
docker-compose.yml           # one compose entrypoint for self-hosting
.github/workflows/ci.yml
.github/workflows/docker-publish.yml
```

Do not move Python files in the first milestone. Add a later mechanical `app/` -> `workers/archibot/` move only after CLI boundaries and tests are stable.

## FastAPI replacement scope

Laravel replaces the normal app layer: UI pages, JSON APIs, auth/session middleware, per-user permissions, settings, audit logs, review queue CRUD, dashboard/status read models, job orchestration endpoints, Paperless proxy/preview endpoints, and setup flow. FastAPI should not remain a permanent app-layer dependency.

Python may keep non-HTTP CLIs and, if needed temporarily, an internal worker-only API during migration. At final switch-over remove old FastAPI app-layer files immediately while retaining Python worker/runtime code that still has a role.

## Python stays

Keep Python for:

- Ollama classification, OCR correction, embeddings, judge prompts, and prompt files.
- Paperless document fetch/patch/entity execution until Laravel proves a cleaner service boundary.
- sqlite-vec/FTS indexing and hybrid search.
- Manual/scheduled/event worker jobs.
- MCP runtime and tools initially, with auth delegated to Laravel-issued MCP tokens.
- Telegram command execution initially, with identity and permissions delegated to Laravel.

Later candidates for Laravel ownership: simple Paperless read proxies, settings CRUD, audit log display, review queue CRUD, and dashboard read models. Keep AI/vector-heavy code in Python unless a clear PHP equivalent reduces complexity.

## Laravel owns

Laravel owns global app setup, Paperless connection settings, user sessions, per-user encrypted Paperless tokens, admin authorization, MCP token management, Telegram linkage settings, audit logs, fresh review queue state, settings UI, Svelte app shell, API orchestration, queue/job records, and Docker health for the normal app.

## Do not touch during early migration

Keep the current FastAPI/Svelte stack functional on `feat/laravel` until Laravel has feature parity. Do not remove `app/`, `frontend/`, current tests, current Docker runtime, prompt files, sqlite-vec schema, MCP tools, Telegram handler, or Paperless/Ollama clients in early milestones.

## Auth, users, and permissions design

Use Laravel default session cookies for the GUI. Users are not independent local identities; a Laravel `users` row is a local cache/profile for a successfully authenticated Paperless user on the configured global Paperless server. Login requires Paperless username/password, verifies the user against Paperless, stores only needed profile identifiers plus encrypted Paperless token/session material, and refreshes admin status on every login.

Authorization uses Laravel policies/gates:

- `is_admin` mirrors Paperless superuser/admin status.
- Admin-only: global Paperless connection, Ollama settings, classification/review settings, audit log viewing, setup reset consequences.
- Per-user: own Paperless token status, MCP tokens, Telegram bot token/linking.
- Document access: live Paperless permission check before preview/detail/action.

## Paperless-NGX username/password login and token model

Preferred flow:

1. Setup stores one global Paperless base URL after admin verification.
2. Login posts username/password to Paperless.
3. If Paperless returns/creates a token from credentials, store that token encrypted with Laravel's app key in the local `users` table or related `paperless_identities` table.
4. Fetch the Paperless current-user endpoint and store Paperless user id, username, display name/email if available, and superuser/admin flag.
5. Do not store the Paperless password.
6. If token creation/fetch is not available on the deployed Paperless version, complete password verification but ask the user to paste their Paperless API token, with UI instructions. Store the pasted token encrypted. If a password must be retained for a specific endpoint, store it encrypted only after explicitly documenting that version-specific need.

## Paperless auth/token endpoints and assumptions

Document and implement against current Paperless-NGX API behavior at implementation time. Assumptions to verify with integration tests/manual calls:

- Token auth for normal API calls uses `Authorization: Token <token>`; the existing Python client already uses this with Paperless API v5 accept headers.
- Paperless user tokens are commonly available through Django REST Framework token auth endpoints, historically `POST /api/token/` with `username` and `password`, returning `{ "token": "..." }` on supported versions/configurations.
- Current user details/permissions should be fetched from a Paperless endpoint such as `/api/users/` filtered to the authenticated user or a current-user endpoint if provided by the installed Paperless version. The implementation must verify the exact endpoint and fields for `is_superuser`/admin status against the minimum supported Paperless-NGX version.
- If `/api/token/` is unavailable or disabled, fallback to user-pasted API token and verify it by calling a cheap authenticated endpoint before login completes.

The Laravel plan must include an integration note recording the tested Paperless-NGX version and exact endpoint responses before switch-over.

## Paperless superuser admin mapping

On every successful login, Laravel refreshes the user's Paperless profile and sets local `is_admin` from Paperless `is_superuser` or the equivalent admin flag. Admin privileges are not granted from environment variables or local-only role edits. If Paperless is unavailable during login, deny login and show an unavailable state rather than using stale admin data.

## First-run setup, admin verification, and CLI reset

- Before setup is complete, only setup routes and health routes are publicly reachable.
- Setup wizard collects Paperless base URL and credentials for a Paperless superuser/admin.
- Wizard verifies the Paperless URL, authenticates the admin, confirms superuser/admin status, imports existing settings where possible, stores global connection settings, creates/updates the admin's local user profile, stores that user's Paperless token encrypted, and marks setup complete.
- Setup wizard values win over imported settings.
- After setup is complete, setup routes return 404/disabled unless reset by CLI.
- Add an Artisan command such as `php artisan archibot:setup-reset --token` that clears setup-complete state and prints a random temporary setup token expiring after 10 minutes. After CLI reset, setup requires that token plus successful Paperless admin verification.
- Store setup state and reset-token hash/expiry in Laravel SQLite, not environment variables.

## Live per-document permission checks and caching

For every document preview/detail/write action, Laravel verifies access with the user's Paperless token by fetching that specific document (`GET /api/documents/{id}/` or equivalent). Do not cache a full allowed-document list. Cache targeted decisions per `(paperless_user_id, document_id, action)` for up to 5 minutes only after a successful Paperless response. Prefer Laravel array cache for a single-process deployment; if multiple PHP workers make that ineffective, use SQLite/file cache with short TTL.

If Paperless is unavailable, do not allow access from cache. Return an unavailable/error state requiring Paperless to be reachable. Cache is performance-only, never an authority when upstream is down.

## MCP token and permission approach

Laravel issues MCP tokens per user:

- Nameable, long-lived until revoked.
- Raw token shown once at creation.
- Store only a hash, token name, user id, last-used timestamp, created/revoked timestamps.
- Create/revoke events are audit logged; individual tool calls are not audit logged by default.
- MCP tokens inherit the linked Paperless-authenticated user's permissions. Python MCP validates token by calling a Laravel CLI/API verifier or reading a signed/hashed token table through a narrow interface, then performs live Paperless document permission checks through Laravel or with the user's encrypted Paperless token material supplied by Laravel.
- `MCP_ENABLE_WRITE=false` remains a global environment-controlled kill switch; when false, write tools are disabled regardless of user permissions.

## Telegram user mapping and linking decision

Telegram bot token is per user, not global. Each user stores their own Telegram bot token encrypted in Laravel. Linking flow:

1. User saves bot token in their settings.
2. Laravel/Python starts or registers polling/webhook for that user's bot.
3. User sends `/start` to their bot.
4. Python records a pending Telegram identity with chat/user id and nonce.
5. Web UI shows pending request; the logged-in user confirms it.
6. Laravel stores the Telegram identity mapping and audit logs link/unlink events.

Telegram actions run with the linked user's Paperless permissions. Telegram write/destructive actions follow normal user permissions only; they are not controlled by `MCP_ENABLE_WRITE`.

## Settings access control

Admin-only settings: global Paperless connection, Ollama URL/models/timeouts, classification/review thresholds, OCR settings, audit retention, global scheduler defaults, and global worker behavior.

Per-user settings: Paperless token status/re-entry, MCP tokens, Telegram bot token/linking, user notification preferences.

Secrets are masked in reads and write-only after saving. Laravel stores secrets encrypted with the app key unless implementation discovers a strong reason for a separate key.

## Audit logging and rotation

Store audit logs in Laravel SQLite. Audit: global Paperless connection changes, admin settings changes, prompt changes, MCP token create/revoke, Telegram link/unlink, setup reset/completion, and sensitive secret rotations. Logs include actor user id, Paperless username, event type, target type/id, non-secret metadata, IP/user agent where available, and timestamp. Admins can view logs. Add configurable retention with default 7 days and a scheduled pruning job.

## Settings import/migration

During first setup, Laravel reads existing settings from environment and `/data/config.env` where possible. Import non-secret and secret values into Laravel settings tables before wizard save, then apply wizard values as authoritative for conflicts. Later UI saves override setup/imported values. No separate later import tool is required.

Settings that were only in browser local storage cannot be imported server-side reliably; document them as not automatically migratable unless the old frontend sends them during setup. Current server settings are primarily env plus `/data/config.env`.

## Review queue ownership

Laravel starts with fresh review queue state and no migration of old pending suggestions. The Laravel schema should model review suggestions, original/proposed fields, status, confidence/judge metadata, context documents, created/updated/committed timestamps, and audit fields. During migration, old FastAPI review state remains in the Python DB for the old UI only.

## Data, database, and settings ownership

Use `/data/laravel/database.sqlite` for Laravel app state and `/data/laravel/storage` for Laravel storage/cache/logs. Keep existing Python data separate, e.g. current `/data/archibot.db`, `/data/config.env`, sqlite-vec/FTS/OCR cache, and job tables, until a later worker schema split. Laravel owns settings after setup; Python workers receive settings from Laravel via environment export, CLI arguments, generated worker config, or a narrow settings JSON file.

## Existing SQLite/vector DB assessment

The current SQLite DB is not just app state; it contains sqlite-vec embeddings, FTS5 index, OCR cache, poll cycles, phase timings, job events, processed document idempotency, suggestions, entity whitelists/blacklists, errors, audit_log, and app_state. Because sqlite-vec extension loading and vector dimensions are Python-centric, do not merge it into Laravel's app DB early. Keep Laravel SQLite separate. Later, split Python worker DB into a worker-owned database for embeddings/OCR/idempotency and migrate Laravel-owned review/settings/audit state out of the old schema.

## Laravel-to-Python boundary options

Options evaluated:

1. CLI calls from Laravel jobs to Python commands. Simple, debuggable, works in one container, easy manual invocation, no internal network auth. Needs structured JSON output and job status files/DB rows.
2. Internal HTTP worker service. Familiar for APIs but keeps a web server around and risks making FastAPI permanent.
3. Shared SQLite queues/tables. Simple but can couple PHP and Python to the same schema and locking behavior.
4. Filesystem dropbox under `/data/jobs`. Very simple but weaker querying/status unless wrapped carefully.
5. External Redis/queue. Robust but violates the self-hosting simplicity preference unless scale requires it.

## Recommended Laravel-to-Python boundary

Use Laravel database queues plus Python CLI commands as the first reliable boundary. Laravel owns job records and queues. A Laravel queued job invokes Python with explicit command names and JSON input files, for example:

```bash
python -m app.cli process-doc --input /data/jobs/<id>.json --output /data/jobs/<id>.result.json
python -m app.cli poll --mode inbox --job-id <id>
python -m app.cli reindex --job-id <id>
```

The Python command writes structured progress/events/results to files or a worker event table that Laravel imports/streams. Manual UI actions, scheduled Laravel tasks, and event/webhook actions all enqueue the same Laravel job types. This avoids keeping FastAPI as an internal service and keeps CLI options desirable for operators.

## Queue strategy

Use Laravel's database queue with SQLite initially. It is sufficient for one self-hosted container and avoids Redis. Run a Laravel queue worker under supervisord/s6 or a simple process manager in the combined image. If SQLite locking becomes an issue under heavy background work, first reduce concurrency to one worker and batch events; only introduce Redis if measured contention cannot be solved simply.

## Docker Compose/self-hosted deployment approach

Modify the existing `docker-compose.yml` on `feat/laravel`, not a parallel compose file. During migration, keep the current `archibot` service usable. Add Laravel environment variables, mount the same `archibot_data:/data`, expose the Laravel HTTP port when the Laravel app is ready, and keep Ollama/Paperless network guidance. Before switch-over, compose should run either old FastAPI mode or Laravel mode explicitly; after switch-over, Laravel is the default app server.

## Combined image strategy

Prefer one combined image after the Laravel starter exists:

- Build Svelte assets in the Laravel app using Node.
- Install PHP extensions needed by Laravel SQLite, queues, and HTTP serving.
- Install Python runtime/dependencies for workers and sqlite-vec.
- Copy `laravel/`, Python worker code, and `prompts/`.
- Start PHP app server/web server, Laravel queue worker, scheduler loop, and optional Python MCP/Telegram workers under one init/process manager.

This is practical for self-hosting and preserves a single GHCR image. If image size or security scans become painful, split only after measuring.

## CI/GHCR image publishing strategy

The CI workflow already runs Python lint/format/tests, frontend checks/build/Playwright smoke tests, Docker build, and image scans. Extend it later with Laravel steps: Composer install/cache, PHP lint/Pint, PHPStan if adopted, Pest/PHPUnit feature tests with SQLite, npm build/tests inside `laravel/`, and Docker startup smoke.

`docker-publish.yml` now runs from successful `CI` workflow completions on `main` and `feat/laravel`, preventing image publish from untested pushes. Tags:

- `ghcr.io/pfriedrich84/archibot:latest` for `main` after CI success.
- `ghcr.io/pfriedrich84/archibot:feat-laravel` for `feat/laravel` after CI success.
- `ghcr.io/pfriedrich84/archibot:sha-<short>` for tested commits.

## Frontend rebuild strategy

Start from the official Laravel Svelte starter conventions instead of copying the old SvelteKit app wholesale. Port reusable components and behavior from `frontend/src/lib/components`, `frontend/src/lib/types.ts`, and page implementations. Keep the current frontend running until replacement pages pass tests. A redesign is allowed, but every checklist item must remain available before switch-over.

## API/frontend compatibility strategy

Compatibility means behavior and feature coverage, not exact URL shape. Laravel can either temporarily expose `/api/v1` compatible endpoints to ease porting or define new route names consumed by new Svelte pages. For preview/document access, Laravel must enforce Paperless user permissions. For worker actions, Laravel returns its own job ids/status and bridges to Python.

## Testing strategy

- Keep all existing Python tests passing until switch-over.
- Add Laravel feature tests for setup, login, Paperless token fallback, admin mapping, settings authorization, audit log writes, MCP token lifecycle, Telegram linking, document permission checks, review queue mutations, job enqueueing, and unavailable Paperless behavior.
- Use in-memory SQLite for most Laravel feature tests for speed, plus a smaller suite against a runtime-like `/tmp/archibot-test-data/laravel/database.sqlite` layout to catch path, migration, queue, and storage issues.
- Adopt the official starter kit frontend test approach. Port current Vitest component tests and Playwright navigation smoke tests into `laravel/`.
- Add Docker startup smoke: build image, run container with minimal env and `/data` volume, verify Laravel health endpoint, queue worker boot, and Python CLI availability.
- Before image publishing and switch-over, require Python tests, old frontend tests while still present, Laravel PHP tests, Laravel frontend tests, and Docker build/startup tests.

## Step-by-step migration roadmap

1. Push `feat/laravel` and confirm CI publishes `feat-laravel` only after CI success.
2. Create `laravel/` with the latest official Laravel Svelte starter kit.
3. Add Laravel CI steps without changing runtime behavior.
4. Define Laravel SQLite schema for users, Paperless identities, settings, setup state, audit logs, review suggestions, MCP tokens, Telegram links, jobs/events.
5. Implement first-run setup wizard with Paperless admin verification, setup-complete state, CLI reset token, and settings import from env/`/data/config.env`.
6. Implement Paperless-backed login/session flow, token fallback UI, encrypted token storage, and admin refresh on login.
7. Implement settings UI/API with admin/per-user boundaries, masked secrets, audit logs, and prompt/settings migration decisions.
8. Implement review queue schema/pages fresh in Laravel and bridge worker-created suggestions into Laravel-owned tables.
9. Add Laravel job records/database queues and Python CLI invocation boundary for manual poll, poll-all, reindex, and single document processing.
10. Port dashboard, processing, errors, inbox, entity approvals, stats, embeddings, chat, and preview pages one by one, with tests.
11. Implement MCP token management in Laravel and adapt Python MCP auth to validate Laravel tokens and enforce Paperless permissions.
12. Implement per-user Telegram token storage/linking and adapt Python Telegram handling to use linked user permissions.
13. Convert Dockerfile to combined Laravel+Python image and update `docker-compose.yml` for Laravel mode.
14. Run full feature parity checklist, Docker startup, and image-tag testing using `feat-laravel`/`sha-*` tags.
15. Switch over by merging `feat/laravel` to `main`; remove old FastAPI app-layer files immediately, keep/move Python worker/runtime code.

## First migration milestone

Milestone 1 should be reviewable and low risk: add the official Laravel Svelte starter in `laravel/`, wire CI to install/build/test it, add a Laravel health page, and document/run it without changing the existing FastAPI/Svelte deployment. This validates the PHP/Svelte toolchain, keeps current behavior untouched, and prepares for setup/auth implementation.

## Image tag / branch testing approach

Test `ghcr.io/pfriedrich84/archibot:feat-laravel` on the branch after CI success. For reproducibility, test `sha-<short>` tags for exact commits. Do not use the branch image for production until the feature checklist and switch-over tests pass. `main` remains stable and `latest` remains the current FastAPI image until merge.

## Switch-over approach

Merge `feat/laravel` into `main` only when Laravel login/auth and all current frontend/backend features work. At merge time, remove FastAPI HTTP/API app-layer files and old frontend build paths immediately. Retain Python worker/runtime files that still have a role, preferably moved under `workers/` if that mechanical move has already been tested. No separate rollback plan is needed because `main` stays untouched until then.

## Future German UI localization

The current UI has German labels. The first Laravel migration does not require a full localization framework, but new text should be centralized enough to make German localization straightforward later. Do not block auth/security work on localization.

## Risks and trade-offs

- Two runtimes increase Docker and CI complexity; one combined image and CLI boundaries keep this manageable.
- Paperless token endpoints may vary by version; verify endpoints early and keep fallback to pasted tokens.
- Live permission checks add latency; targeted 5-minute caching helps but must never authorize during Paperless outages.
- SQLite queues are simple but can lock under concurrency; start with low concurrency and measure before adding Redis.
- Moving review state to Laravel while Python still generates suggestions requires a stable bridge; use structured CLI outputs and Laravel-owned imports.
- Per-user Telegram bots are more complex than one global bot but align with the permission model and user ownership.
- MCP token enforcement across PHP/Python must avoid leaking raw tokens or bypassing Paperless document permissions.

## Concrete follow-up TODOs

- [ ] Push local `feat/laravel` with `git push -u origin feat/laravel`.
- [ ] Scaffold `laravel/` using the latest official Laravel Svelte starter kit and commit without altering the current app runtime.
- [ ] Add Laravel CI steps for Composer, PHP tests, Laravel frontend checks/build, and starter kit frontend tests.
- [ ] Verify Paperless-NGX auth/token/current-user endpoints against a supported Paperless version and record exact responses in docs/tests.
- [ ] Implement Laravel setup wizard, setup-complete state, admin verification, CLI reset token, and first-run settings import.
- [ ] Implement Paperless-backed login with encrypted per-user token storage and pasted-token fallback.
- [ ] Design and migrate Laravel settings/audit/review/MCP/Telegram/job schemas.
- [ ] Build the Python CLI JSON job boundary and Laravel database queue orchestration.
- [ ] Port frontend pages according to the checklist and add Laravel frontend/Playwright tests.
- [ ] Update Dockerfile and `docker-compose.yml` for the combined Laravel + Python image after the starter app boots.
