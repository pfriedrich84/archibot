# Agent Memory

Durable project memory for future agents. Store only evidence-based, non-secret repository knowledge here.

## Current durable observations

- ArchiBot is intentionally local-first: Paperless-NGX, Ollama, PostgreSQL, and Docker are the core operational assumptions.
- The repository consistently separates Python processing/runtime concerns from Laravel/Svelte UI and orchestration concerns.
- The project favors safety gates around Paperless mutations: review queues, whitelists, and explicit approval are product boundaries.
- Supply-chain checks are already part of CI and local docs; dependency changes should preserve that posture.

## What not to store

- Secrets, tokens, `.env` contents, private document data, customer data, chat transcripts, or temporary debugging notes.
- Guesses without repository evidence.

## How to update

Add short, dated entries only when the knowledge is likely to matter across future tasks.
