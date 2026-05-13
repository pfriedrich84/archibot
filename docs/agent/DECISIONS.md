# Agent Decision Log

Lightweight decisions that guide future implementation. Use ADRs in `docs/developer/adr/` for major architectural decisions.

## Active decisions

- **AGENTS.md is canonical for coding agents.** Tool-specific files such as `CLAUDE.md` should remain shims unless a tool has genuinely unique requirements.
- **Manual review and approval flows are product safety boundaries.** Do not bypass them in convenience features without explicit product/security review.
- **Local-first AI is the default.** Native Ollama and local OpenAI-compatible providers are documented local paths; cloud AI dependencies require explicit approval.
- **OpenAI-compatible embeddings set `encoding_format: "float"`.** This is required for LiteLLM/llama.cpp compatibility and prevents provider failures caused by omitted or null encoding formats.
- **Documentation is split by audience.** Agent guidance belongs in `docs/agent/`, contributor implementation docs in `docs/developer/`, and operator/user material in `docs/user/`.
