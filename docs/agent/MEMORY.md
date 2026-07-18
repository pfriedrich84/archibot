# Agent Memory

Durable project memory for future agents. Store only evidence-based, non-secret repository knowledge here.

## Current durable observations

- ArchiBot is intentionally local-first: Paperless-NGX, Ollama-compatible/OpenAI-compatible local providers, PostgreSQL, and Docker are the core operational assumptions.
- The repository consistently separates Python processing/runtime concerns from Laravel/Svelte UI and orchestration concerns.
- The project favors safety gates around Paperless mutations: review queues, whitelists, and explicit approval are product boundaries.
- Supply-chain checks are already part of CI and local docs; dependency changes should preserve that posture.
- 2026-05-13: OpenAI-compatible embedding backends require OpenAI-compatible `/v1/embeddings` calls to include `encoding_format: "float"`; avoid sending `encoding_format: null`.
- 2026-07-18: Reset state is PostgreSQL/Laravel-owned. Keep the `archibot reset` CLI UX delegating to `php artisan archibot:reset`. Product reset must not inspect or remove a legacy `classifier.db`; an existing file stays inert for explicit operator export, rollback, archival, or deletion.

## What not to store

- Secrets, tokens, `.env` contents, private document data, customer data, chat transcripts, or temporary debugging notes.
- Guesses without repository evidence.

## How to update

Add short, dated entries only when the knowledge is likely to matter across future tasks.
