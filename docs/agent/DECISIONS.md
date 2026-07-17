# Agent Decision Log

Lightweight decisions that guide future implementation. Use ADRs in `docs/decisions/` for major architectural decisions.

## Active decisions

- **AGENTS.md is canonical for coding agents.** Tool-specific files such as `CLAUDE.md` should remain shims unless a tool has genuinely unique requirements.
- **Manual review and approval flows are product safety boundaries.** Do not bypass them in convenience features without explicit product/security review.
- **Local-first AI is the default.** Ollama-compatible and local OpenAI-compatible providers are documented local paths; cloud AI dependencies require explicit approval.
- **OpenAI-compatible embeddings set `encoding_format: "float"`.** This is required for OpenAI-compatible/llama.cpp compatibility and prevents provider failures caused by omitted or null encoding formats.
- **AI-provider is the processing seam.** Ollama-compatible endpoints are one adapter behind the neutral AI-provider module; legacy `OLLAMA_*` setting names remain supported for compatibility and do not imply Ollama-only processing architecture.
- **Documentation is split by audience.** Agent guidance belongs in `docs/agent/`, contributor implementation docs in `docs/developer/`, and operator/user material in `docs/user/`.
- **Trusted classification context means absence of the inbox tag.** Event-driven embeddings should index only Paperless documents without the configured inbox tag; inbox-tagged documents must not be embedded for trusted classification context.
- **Model confidence must not authorize Paperless writes.** ADR-0018 containment is implemented: `auto_commit_confidence` is effectively fixed at zero across Laravel export and Python, and model/judge output remains pending. Do not restore this behavior without the ADR's deterministic gates and explicit approval.
- **Legacy Paperless webhook endpoints are removed.** Do not reintroduce long-term compatibility aliases; use `/api/webhooks/paperless` or `/webhook`.
- **Webhook enqueue failure should encourage Paperless retry.** After persisting a delivery, downstream enqueue failure should return a non-2xx response rather than hiding the failure behind internal-only recovery.
- **Manual force reprocess always creates a new run.** Attach/retry semantics are separate from explicit user-selected force reprocess.
- **First pgvector context search may be vector-only.** Do not preserve or document SQLite hybrid search as target behavior; update stale SQLite references during migration.
- **Suggestion work requires Paperless document-change permission for non-admin users.** Non-admin ArchiBot users may accept, reject, or otherwise work on suggestions only when they have the right to change the corresponding Paperless document.
- **Direct webhook enqueue is temporary/local-development infrastructure.** It must not become the long-term durable webhook processing interface.
- **Laravel database queues are the only event-driven transport.** ADR-0015 supersedes the Absurd queue decision; ADR-0017 requires complete Absurd removal after parity migration. Laravel queued jobs invoke fixed, allowlisted Python actor commands while PostgreSQL pipeline tables remain the durable source of truth.
- **Runtime ownership has one durable model.** ADR-0017 removes productive SQLite processing and makes Laravel the sole orchestration, Pipeline Start, readiness-policy and actor-dispatch owner. Python owns processing plus domain execution lifecycle; its mandatory lease-owned execution-time readiness revalidation is defense-in-depth, not orchestration ownership. CLI, UI and MCP must use this same model.
- **Paperless auth is login-derived, not operator token based.** Do not document or require a global `.env` `PAPERLESS_TOKEN`; use Laravel setup/login and per-user Paperless tokens internally. See ADR-0014.
