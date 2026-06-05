# Agent Project Brief — ArchiBot

Concise project context for coding agents. For durable details, prefer the canonical docs linked below instead of duplicating them here.

## Purpose

ArchiBot is a self-hosted, Docker-first assistant for Paperless-NGX. It is being migrated from polling/subprocess-oriented processing to an event-driven architecture where Paperless webhooks are the primary trigger and periodic polling remains reconciliation/fallback.

Suggested metadata includes title, date, correspondent, document type, storage path, and tags.

## Core architecture target

- **Paperless webhooks** are the primary low-latency trigger for new or changed documents.
- **Periodic polling** remains automatic every 600 seconds as reconciliation/fallback and must use the same pipeline-start/dedupe/lock logic as webhooks.
- **Laravel + Inertia/Svelte** owns setup, login, settings, review UI, dashboard/operations UI, command API, webhook ingestion and admin-only job controls.
- **Python queue-backed actors** own document processing, Paperless/AI-provider calls, embeddings, OCR correction, classification, committing and maintenance execution.
- **PostgreSQL + pgvector** are the durable source of truth for state, progress, retries, events, audit data and embedding similarity search.
- **Laravel database queues** provide the event-driven transport; there is no separate broker service in the target path. Laravel queued jobs invoke fixed Python actor commands while PostgreSQL pipeline tables remain the durable source of truth.
- **Ollama-compatible and OpenAI-compatible providers** provide local or configured LLM classification, optional OCR correction, embeddings, judge pass and RAG chat.

## Classification model

ArchiBot is context-aware: it embeds a new document, finds similar already reviewed documents, and includes their confirmed classifications as examples for the LLM. Inbox or unreviewed documents must not be trusted as classification context.

Safety boundaries:

- Existing Paperless storage paths are immutable.
- Unknown entities go through approval/whitelist flow.
- Manual review is the default safety path.
- OCR corrections stay local and are not written back to Paperless content.

## Important paths

```text
app/                  Python worker, CLI, MCP runtime, Paperless/AI-provider clients
app/pipeline/         OCR, context building, classification, commit pipeline
app/mcp_tools/        MCP tool implementations
laravel/              Laravel/Inertia/Svelte UI and API
prompts/              LLM system prompts
docs/                 User/developer documentation
docs/agent/           Tool-neutral agent instructions
tests/                Python test suite
scripts/              Maintenance and CI helper scripts
.graphify/            Committed agent knowledge-graph artifacts plus ignored local runtime state
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
- [`../../.graphify/GRAPH_REPORT.md`](../../.graphify/GRAPH_REPORT.md) — high-level Graphify repository graph report for agent orientation.

## Agent docs

- [`RULES.md`](RULES.md) — non-negotiable project rules.
- [`CONSTRAINTS.md`](CONSTRAINTS.md) — deployment, runtime, data, and compatibility constraints.
- [`CODING.md`](CODING.md) — implementation guidance.
- [`REVIEW.md`](REVIEW.md) — review checklist and focus areas.
- [`CHECKS.md`](CHECKS.md) — validation commands.
- [`WORKFLOWS.md`](WORKFLOWS.md) — reusable maintenance workflows.
- [`SAFETY.md`](SAFETY.md) — safe/unsafe operations.
- [`SUPPLY_CHAIN.md`](SUPPLY_CHAIN.md) — dependency and container safety guidance.
- [`MEMORY.md`](MEMORY.md) — durable non-secret repo memory.
- [`DECISIONS.md`](DECISIONS.md) — lightweight decision log.
- [`ANTI_PATTERNS.md`](ANTI_PATTERNS.md) — approaches to avoid.
- [`DEFINITION_OF_DONE.md`](DEFINITION_OF_DONE.md) — completion criteria.
- [`ASSESSMENT.md`](ASSESSMENT.md) — governance assessment and next steps.
- [`CHANGELOG_AGENT.md`](CHANGELOG_AGENT.md) — governance-system changelog.
