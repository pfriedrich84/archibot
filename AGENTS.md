# AGENTS.md — ArchiBot Agent Instructions

Tool-neutral entry point for coding agents working in this repository. Keep this file short; put durable details in `docs/agent/`.

## Read first

1. [`docs/agent/RULES.md`](docs/agent/RULES.md) — non-negotiable project rules and domain invariants.
2. [`docs/agent/CONSTRAINTS.md`](docs/agent/CONSTRAINTS.md) — deployment, runtime, data, and compatibility constraints.
3. [`docs/agent/PROJECT.md`](docs/agent/PROJECT.md) — project context, architecture notes, and important implementation details.
4. [`docs/agent/CODING.md`](docs/agent/CODING.md) — coding conventions and implementation guidance.
5. [`docs/agent/CHECKS.md`](docs/agent/CHECKS.md) — validation commands to run before finishing code changes.
6. [`docs/agent/TOOLING.md`](docs/agent/TOOLING.md) — approved MCP/tooling policy for coding agents.
7. [`docs/agent/SAFETY.md`](docs/agent/SAFETY.md) — safe/unsafe operations for agents.
8. [`docs/agent/DEFINITION_OF_DONE.md`](docs/agent/DEFINITION_OF_DONE.md) — completion criteria for implementation tasks.

## Project docs

- [`docs/prompts/pi-dev-event-driven-migration.md`](docs/prompts/pi-dev-event-driven-migration.md) — governing migration prompt for the event-driven architecture.
- [`docs/implementation-plan-event-driven-archibot.md`](docs/implementation-plan-event-driven-archibot.md) — target architecture and migration plan.
- [`docs/architecture/job-control-model.md`](docs/architecture/job-control-model.md) — current temporary job-control model and migration rules.
- [`docs/architecture/`](docs/architecture/) — detailed architecture rules for webhooks, polling, progress, retries, observability and authorization.
- [`docs/decisions/`](docs/decisions/) — accepted architecture decisions.
- [`docs/governance/`](docs/governance/) — repository governance, trust boundaries, release governance, agent workflow and review checklist.
- [`docs/governance/trust-boundaries.md`](docs/governance/trust-boundaries.md) — runtime, CI, tool, and integration trust boundaries.
- [`docs/governance/release-governance.md`](docs/governance/release-governance.md) — release, rollback, and provenance expectations.
- [`docs/developer/architecture.md`](docs/developer/architecture.md) — current architecture and data flow.
- [`docs/user/workflow.md`](docs/user/workflow.md) — review, approval, and whitelist workflow.
- [`docs/user/configuration.md`](docs/user/configuration.md) — environment and runtime configuration.
- [`docs/user/installation.md`](docs/user/installation.md) — Docker-first installation and local development.
- [`docs/agent/SUPPLY_CHAIN.md`](docs/agent/SUPPLY_CHAIN.md) — dependency and container safety guidance.
- [`docs/agent/AUTORESEARCH.md`](docs/agent/AUTORESEARCH.md) — optional metric-driven experiment workflow.
- [`.graphify/GRAPH_REPORT.md`](.graphify/GRAPH_REPORT.md) — committed knowledge-graph summary for repository orientation; use `.graphify/graph.json` through Graphify tools rather than reading raw JSON manually.

## Event-driven migration summary

Archibot is being migrated to an event-driven architecture using Paperless webhooks, periodic polling reconciliation, Dramatiq, RabbitMQ, PostgreSQL and pgvector.
Paperless webhooks are the primary trigger; polling remains every 600 seconds as reconciliation/fallback.
Document processing must never start before the embedding index is complete.
Progress, retry and recovery state must be durable in PostgreSQL.
Only admins may control jobs via Laravel actions guarded by `is_admin()`.
Per-document reprocess must be possible manually through an admin Laravel button and automatically through relevant webhooks.
Use the existing Laravel dashboard as the operations console. Extend it rather than creating a separate new UI.
CLI commands must behave exactly like the corresponding Laravel UI actions: same backend, config source, durable state, progress semantics, storage target, and side effects. Never leave CLI on a legacy SQLite/subprocess path while the UI uses PostgreSQL/pgvector/event-driven flows.
Reset is PostgreSQL/Laravel-owned: keep the `archibot reset` CLI entrypoint for operators, but it must delegate to `php artisan archibot:reset` in the background and must never silently reset only legacy SQLite state.
Do not extend the legacy Laravel-subprocess/Python-CLI worker path.
Laravel `worker_jobs` is a temporary stabilization layer.
Do not add permanent architecture only to `worker_jobs`.
New durable pipeline functionality should target `commands`, `pipeline_runs`, `pipeline_events` and Python actors.

Before finishing code changes, run the relevant checks from [`docs/agent/CHECKS.md`](docs/agent/CHECKS.md). Graphify-only artifact refreshes must pass `python3 scripts/check_graphify_artifacts.py` and should commit only `.graphify/GRAPH_REPORT.md`, `.graphify/graph.json`, and `.graphify/scope.json`.
