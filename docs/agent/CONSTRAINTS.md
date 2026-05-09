# Agent Constraints

Evidence-based constraints for ArchiBot changes.

## Deployment and runtime

- ArchiBot is Docker-first; the event-driven target also includes PostgreSQL/pgvector and RabbitMQ services for local/deployed stacks.
- Runtime state must be durable. The target source of truth is PostgreSQL; do not create hidden in-memory or log-only job state.
- Paperless-NGX and Ollama/LiteLLM-compatible providers are external services configured by environment variables or the setup UI.
- Default timezone is `Europe/Vienna`; date and timezone behavior must remain configurable and consistent between Python and Laravel/Svelte.

## Local-first AI constraints

- LLM, embedding, OCR correction, judge, and RAG behavior are designed around local Ollama models.
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
- The migration target replaces SQLite/sqlite-vec state and vector search with PostgreSQL/pgvector.
- Do not extend the legacy Laravel-subprocess/Python-CLI worker path for new pipeline behavior.
- Do not introduce a permanent `legacy|dramatiq` backend compatibility mode.
- Keep Laravel and Python aligned on the shared PostgreSQL pipeline state model.
