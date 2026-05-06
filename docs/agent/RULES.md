# Agent Rules

Core rules for coding agents working on ArchiBot.

## Product safety

- Keep ArchiBot single-container and Docker-first.
- Do not overwrite existing Paperless storage paths.
- Keep manual review as the default safety path.
- Do not use inbox/unreviewed documents as trusted classification context.
- Do not create new Paperless tags, correspondents, or document types outside the approval/whitelist flow.
- Keep OCR corrections local; never write corrected OCR text back to Paperless content.
- Degrade gracefully: if OCR, embeddings, judge, Telegram, or optional integrations fail, continue where safe and surface the error for review.

## Change discipline

- Prefer small, reviewable changes.
- Update docs when behavior changes.
- Run relevant checks before finishing code changes; see [`CHECKS.md`](CHECKS.md).
- Do not expose or modify secrets from `.env`.

## Domain invariants

- ArchiBot suggests metadata first; Paperless updates happen only after explicit approval or configured safe automation.
- A Paperless storage path that already exists on a document is authoritative.
- Review queues and whitelists are safety boundaries, not implementation details.
- Python owns document processing, embeddings, Ollama calls, and MCP runtime; Laravel/Svelte owns UI, setup, settings, review, and worker-job orchestration.
