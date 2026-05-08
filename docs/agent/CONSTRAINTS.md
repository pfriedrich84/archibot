# Agent Constraints

Evidence-based constraints for ArchiBot changes.

## Deployment and runtime

- ArchiBot is Docker-first and optimized for a single-container deployment shape.
- Runtime state is local and persistent under `/data`; do not assume a hosted database or cloud services.
- Paperless-NGX and Ollama are external services configured by environment variables or the setup UI.
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
- SQLite and `sqlite-vec` are part of the core storage/search model.
- Keep CLI and GUI job state semantics aligned.
