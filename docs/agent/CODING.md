# Agent Coding Guidance

## Change style

- Prefer targeted, reviewable changes over broad rewrites.
- Preserve the Python/Laravel boundary: Python owns document processing and model calls; Laravel/Svelte owns UI, settings, review, and orchestration.
- Update tests and docs when behavior changes.
- Avoid unrelated formatting churn.

## Python

- Follow Ruff configuration from `pyproject.toml`.
- Keep document-processing logic deterministic and testable where possible.
- Use fixtures or mocks for Paperless/Ollama behavior in tests; do not require real user documents or secrets.

## Laravel / Svelte

- Keep user-facing metadata readable; resolve IDs to labels/names in UI.
- Avoid showing raw JSON as the primary metadata display.
- Preserve explicit safety actions for review, approval, rejection, and whitelist flows.
- Use existing npm/composer scripts from `laravel/package.json` and `laravel/composer.json`.

## Refactoring discipline

- Refactor only the smallest area needed for the task.
- If a broader architectural change looks useful, document it as a follow-up unless explicitly requested.
- Do not weaken authentication, audit logging, MCP token checks, or review gates as part of cleanup.
