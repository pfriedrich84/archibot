# Agent Project Brief — ArchiBot

Concise project context for coding agents. For durable details, prefer the canonical docs linked below instead of duplicating them here.

## Purpose

ArchiBot is a self-hosted, Docker-first assistant for Paperless-NGX. It polls documents tagged `Posteingang`, asks local Ollama models for metadata suggestions, stores suggestions in a review queue, and writes metadata back to Paperless only after approval or explicitly configured safe automation.

Suggested metadata includes title, date, correspondent, document type, storage path, and tags.

## Core architecture

- **Python worker/CLI/MCP** owns document processing, Paperless/Ollama calls, embeddings, OCR correction, classification, committing, and MCP tools.
- **Laravel + Inertia/Svelte** owns setup, login, settings, review UI, inbox UI, entity approvals, audit views, MCP tokens, and worker-job orchestration.
- **SQLite + sqlite-vec** provide local state, audit data, review queues, and embedding similarity search.
- **Ollama** provides local LLM classification, optional OCR correction, embeddings, judge pass, and RAG chat.
- **Single container** is the primary deployment shape; persistent runtime data lives under `/data`.

## Classification model

ArchiBot is context-aware: it embeds a new document, finds similar already reviewed documents, and includes their confirmed classifications as examples for the LLM. Inbox or unreviewed documents must not be trusted as classification context.

Safety boundaries:

- Existing Paperless storage paths are immutable.
- Unknown entities go through approval/whitelist flow.
- Manual review is the default safety path.
- OCR corrections stay local and are not written back to Paperless content.

## Important paths

```text
app/                  Python worker, CLI, MCP runtime, Paperless/Ollama clients
app/pipeline/         OCR, context building, classification, commit pipeline
app/mcp_tools/        MCP tool implementations
laravel/              Laravel/Inertia/Svelte UI and API
prompts/              LLM system prompts
docs/                 User/developer documentation
docs/agent/           Tool-neutral agent instructions
tests/                Python test suite
scripts/              Maintenance and CI helper scripts
```

## Canonical docs

Read these instead of expanding this file with duplicate details:

- [`../developer/architecture.md`](../developer/architecture.md) — system architecture and data flow.
- [`../user/workflow.md`](../user/workflow.md) — review, approval, classification, and tag workflow.
- [`../user/configuration.md`](../user/configuration.md) — environment variables and runtime settings.
- [`../user/installation.md`](../user/installation.md) — Docker-first setup and local development.
- [`../developer/cli.md`](../developer/cli.md) — CLI commands.
- [`../developer/mcp.md`](../developer/mcp.md) — MCP server/tools.
- [`../user/webhooks.md`](../user/webhooks.md) — Paperless webhook integration.
- [`../user/deployment.md`](../user/deployment.md) — deployment notes.

## Agent docs

- [`RULES.md`](RULES.md) — non-negotiable project rules.
- [`CHECKS.md`](CHECKS.md) — validation commands.
- [`WORKFLOWS.md`](WORKFLOWS.md) — reusable maintenance workflows.
- [`SAFETY.md`](SAFETY.md) — safe/unsafe operations.
