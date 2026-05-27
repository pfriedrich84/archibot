# Agent Decision Log

Lightweight decisions that guide future implementation. Use ADRs in `docs/developer/adr/` for major architectural decisions.

## Active decisions

- **AGENTS.md is canonical for coding agents.** Tool-specific files such as `CLAUDE.md` should remain shims unless a tool has genuinely unique requirements.
- **Manual review and approval flows are product safety boundaries.** Do not bypass them in convenience features without explicit product/security review.
- **Local-first AI is the default.** Native Ollama and local OpenAI-compatible providers are documented local paths; cloud AI dependencies require explicit approval.
- **OpenAI-compatible embeddings set `encoding_format: "float"`.** This is required for LiteLLM/llama.cpp compatibility and prevents provider failures caused by omitted or null encoding formats.
- **Documentation is split by audience.** Agent guidance belongs in `docs/agent/`, contributor implementation docs in `docs/developer/`, and operator/user material in `docs/user/`.
- **Trusted classification context means absence of the inbox tag.** Event-driven embeddings should index only Paperless documents without the configured inbox tag; inbox-tagged documents must not be embedded for trusted classification context.
- **Event-driven processing preserves configured auto-commit behavior.** The new Document processing Module should support existing `auto_commit_confidence` semantics while keeping review and permission safeguards intact.
- **Legacy Paperless webhook endpoints may be removed.** `/webhook/new` and `/webhook/edit` do not need long-term compatibility aliases; update user/operator docs when removing them in favor of `/api/webhooks/paperless`.
- **Webhook enqueue failure should encourage Paperless retry.** After persisting a delivery, downstream enqueue failure should return a non-2xx response rather than hiding the failure behind internal-only recovery.
- **Manual force reprocess always creates a new run.** Attach/retry semantics are separate from explicit user-selected force reprocess.
- **First pgvector context search may be vector-only.** Do not preserve or document SQLite hybrid search as target behavior; update stale SQLite references during migration.
- **Suggestion work requires Paperless document-change permission for non-admin users.** Non-admin ArchiBot users may accept, reject, or otherwise work on suggestions only when they have the right to change the corresponding Paperless document.
- **Direct webhook enqueue is temporary/local-development infrastructure.** It must not become the long-term durable webhook processing interface.
