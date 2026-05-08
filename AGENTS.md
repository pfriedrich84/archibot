# AGENTS.md — ArchiBot Agent Instructions

Tool-neutral entry point for coding agents working in this repository. Keep this file short; put durable details in `docs/agent/`.

## Read first

1. [`docs/agent/RULES.md`](docs/agent/RULES.md) — non-negotiable project rules and domain invariants.
2. [`docs/agent/CONSTRAINTS.md`](docs/agent/CONSTRAINTS.md) — deployment, runtime, data, and compatibility constraints.
3. [`docs/agent/PROJECT.md`](docs/agent/PROJECT.md) — project context, architecture notes, and important implementation details.
4. [`docs/agent/CODING.md`](docs/agent/CODING.md) — coding conventions and implementation guidance.
5. [`docs/agent/CHECKS.md`](docs/agent/CHECKS.md) — validation commands to run before finishing code changes.
6. [`docs/agent/SAFETY.md`](docs/agent/SAFETY.md) — safe/unsafe operations for agents.
7. [`docs/agent/DEFINITION_OF_DONE.md`](docs/agent/DEFINITION_OF_DONE.md) — completion criteria for implementation tasks.

## Project docs

- [`docs/developer/architecture.md`](docs/developer/architecture.md) — architecture and data flow.
- [`docs/user/workflow.md`](docs/user/workflow.md) — review, approval, and whitelist workflow.
- [`docs/user/configuration.md`](docs/user/configuration.md) — environment and runtime configuration.
- [`docs/user/installation.md`](docs/user/installation.md) — Docker-first installation and local development.
- [`docs/agent/SUPPLY_CHAIN.md`](docs/agent/SUPPLY_CHAIN.md) — dependency and container safety guidance.
- [`docs/agent/AUTORESEARCH.md`](docs/agent/AUTORESEARCH.md) — optional metric-driven experiment workflow.

## Quick summary

ArchiBot is a single-container, Docker-first Paperless-NGX assistant. It uses a Python worker/CLI/MCP runtime, Laravel + Inertia/Svelte for UI/API, SQLite + sqlite-vec for state and similarity search, and Ollama for local LLMs and embeddings.

Before finishing code changes, run the relevant checks from [`docs/agent/CHECKS.md`](docs/agent/CHECKS.md).
