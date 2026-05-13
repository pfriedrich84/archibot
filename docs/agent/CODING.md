# Agent Coding Guidance

## Change style

- Prefer targeted, reviewable changes over broad rewrites.
- Preserve the Python/Laravel boundary: Python owns document processing and model calls; Laravel/Svelte owns UI, settings, review, and orchestration.
- Keep CLI behavior identical to the Laravel UI path. When updating a workflow such as reindex, polling, embedding builds, review commits, or settings, verify the CLI entry point uses the same backend/config/state as the UI instead of a legacy fallback.
- Update tests and docs when behavior changes.
- Avoid unrelated formatting churn.

## Python

- Follow Ruff configuration from `pyproject.toml`.
- Keep document-processing logic deterministic and testable where possible.
- Use fixtures or mocks for Paperless/AI-provider behavior in tests; do not require real user documents or secrets.

## Laravel / Svelte

- Keep user-facing metadata readable; resolve IDs to labels/names in UI.
- Avoid showing raw JSON as the primary metadata display.
- Preserve explicit safety actions for review, approval, rejection, and whitelist flows.
- Use existing npm/composer scripts from `laravel/package.json` and `laravel/composer.json`.

## External dependency documentation

- When changing code that depends on third-party libraries, frameworks, SDKs, APIs, CLIs, generated clients, or config formats, consult current external documentation first when available.
- Prefer Context7 for current, version-specific public documentation; if Context7 is unavailable or unhelpful, use official docs, release notes, repository README files, or source code and mention the fallback in the summary.
- Do not rely only on model memory for dependency-sensitive implementation details such as Laravel/Svelte APIs, httpx/OpenAI-compatible payloads, Docker/Compose syntax, Dramatiq/RabbitMQ behavior, or Paperless API semantics.

## Refactoring discipline

- Refactor only the smallest area needed for the task.
- If a broader architectural change looks useful, document it as a follow-up unless explicitly requested.
- Do not weaken authentication, audit logging, MCP token checks, or review gates as part of cleanup.
