# Agent Safety

Tool-neutral safety guidance for coding agents.

## Allowed by default

- Read, search, and make targeted edits to repository files.
- Run validation commands listed in [`CHECKS.md`](CHECKS.md).
- Use read-only Git commands for orientation: `git status`, `git diff`, `git log`, `git branch`.
- Install or check dependencies only when the task requires it.
- Read examples and non-secret configuration such as `.env.example`.

## Ask first / avoid unless explicitly requested

- Destructive filesystem operations, especially recursive deletes.
- History rewriting or destructive Git operations such as force-push or hard reset.
- Removing Docker containers/images or persistent data.
- Broad formatting or large refactors unrelated to the task.
- Changing generated lock files without also validating dependency state.

## Never do

- Do not print, copy, or modify secrets from `.env` or runtime data directories.
- Do not bypass the Paperless approval/whitelist flow for new entities.
- Do not weaken review gates, authentication, audit logging, or MCP token checks without an explicit security-driven task.

## Before finishing

Run the relevant checks from [`CHECKS.md`](CHECKS.md), or state clearly why checks were not run for documentation-only or planning-only work.

Follow the commit and push discipline in the root [`AGENTS.md`](../../AGENTS.md). Always report the final commit and push state; supporting safety guidance must not override the canonical repository policy.
