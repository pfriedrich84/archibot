# Agent Constraints

Evidence-based constraints for ArchiBot changes.

## Deployment and runtime

- ArchiBot is Docker-first; the event-driven target includes PostgreSQL/pgvector and the Absurd queue path running on PostgreSQL for local/deployed stacks.
- Runtime state must be durable. The target source of truth is PostgreSQL; do not create hidden in-memory or log-only job state.
- Paperless-NGX and Ollama-compatible/OpenAI-compatible providers are external services configured by environment variables or the setup UI.
- Default timezone is `Europe/Vienna`; date and timezone behavior must remain configurable and consistent between Python and Laravel/Svelte.

## Local-first AI constraints

- LLM, embedding, OCR correction, judge, and RAG behavior are designed around local Ollama or OpenAI-compatible provider models.
- OpenAI-compatible embedding requests must explicitly send `encoding_format: "float"`; never rely on client defaults that might serialize `encoding_format: null`.
- Avoid introducing cloud AI dependencies or telemetry without explicit maintainer approval.
- Model choices should remain configurable; do not hard-code a model unless it is already a documented default.

## Data and safety constraints

- Never overwrite an existing Paperless storage path.
- Keep unreviewed inbox documents out of trusted classification context.
- Unknown tags, correspondents, and document types must pass through the approval/whitelist flow.
- OCR corrections remain local to ArchiBot and must not be written back to Paperless content.
- Do not read, print, or persist secrets from `.env` or runtime data.

## Compatibility constraints

- Python runtime targets Python 3.12.
- Laravel CI uses PHP 8.4 and Node.js 22; `composer.json` currently allows PHP `^8.3`.
- The migration target standardizes state and vector search on PostgreSQL/pgvector.
- Do not extend the legacy Laravel-subprocess/Python-CLI worker path for new pipeline behavior.
- CLI commands must not diverge from Laravel UI behavior. If a UI action uses PostgreSQL/pgvector, Absurd, Laravel-managed settings, or durable pipeline state, the matching CLI command must use that same path or delegate to it; do not keep a separate SQLite/legacy implementation.
- `archibot reset` remains supported for operators, but reset state is PostgreSQL/Laravel-owned and the Python CLI must delegate to Laravel rather than recreating legacy SQLite state.
- Do not introduce a permanent alternate queue backend compatibility mode.
- Keep Laravel and Python aligned on the shared PostgreSQL pipeline state model.
