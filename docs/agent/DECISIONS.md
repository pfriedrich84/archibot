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
- **Event-driven processing preserves configured auto-commit behavior.** The new Document processing Module should support existing `auto_commit_confidence` semantics while keeping review and permission safeguards intact.
- **Legacy Paperless webhook endpoints are removed.** Do not reintroduce long-term compatibility aliases; use `/api/webhooks/paperless` or `/webhook`.
- **Webhook enqueue failure should encourage Paperless retry.** After persisting a delivery, downstream enqueue failure should return a non-2xx response rather than hiding the failure behind internal-only recovery.
- **Manual force reprocess always creates a new run.** Attach/retry semantics are separate from explicit user-selected force reprocess.
- **First pgvector context search may be vector-only.** Do not preserve or document SQLite hybrid search as target behavior; update stale SQLite references during migration.
- **Suggestion work requires Paperless document-change permission for non-admin users.** Non-admin ArchiBot users may accept, reject, or otherwise work on suggestions only when they have the right to change the corresponding Paperless document.
- **Direct webhook enqueue is temporary/local-development infrastructure.** It must not become the long-term durable webhook processing interface.
- **Absurd is the only queue transport.** The target worker path is PostgreSQL-backed Absurd with `absurd-sdk==0.4.0` and the vendored Absurd SQL installed by Laravel migrations; see ADR-0013.
- **Paperless auth is login-derived, not operator token based.** Do not document or require a global `.env` `PAPERLESS_TOKEN`; use Laravel setup/login and per-user Paperless tokens internally. See ADR-0014.
