# ADR-0014: Use Paperless Login-Derived Tokens Instead of Global Env Tokens

## Status

Accepted.

## Context

ArchiBot authenticates users against Paperless-NGX through the Laravel setup and login flow. Paperless returns an API token for the authenticated user, which Laravel stores encrypted on the corresponding ArchiBot user record and uses for permission checks and Paperless write operations.

A separately configured global `PAPERLESS_TOKEN` in `.env` or Docker Compose creates a second authentication path that can drift from the logged-in user model. It also encourages operators to provision long-lived admin tokens manually, bypassing the intended setup/login flow and per-user Paperless authorization semantics.

## Decision

Do not require or document `PAPERLESS_TOKEN` as an operator-provided `.env` / Docker Compose setting.

Paperless authentication is established through Laravel setup/login only. Runtime code may continue to use login-derived Paperless tokens internally, including exporting the effective admin token into Python runtime config as a temporary bridge while Python workers still read environment-style settings. That exported value is implementation detail, not an operator configuration surface.

## Consequences

- `.env.example` and `docker-compose.yml` do not expose `PAPERLESS_TOKEN`.
- Setup and user login are the canonical way to connect ArchiBot to Paperless.
- Non-admin suggestion actions remain tied to the user's Paperless rights.
- Python worker paths that need Paperless access should migrate toward Laravel-managed settings / scoped tokens rather than a manually supplied global token.
- Existing legacy `PAPERLESS_TOKEN` values may still be read during migration where necessary, but new docs and examples must not ask operators to configure one.
