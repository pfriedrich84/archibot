# Agent Decision Log

Lightweight decisions that guide future implementation. Use ADRs in `docs/developer/adr/` for major architectural decisions.

## Active decisions

- **AGENTS.md is canonical for coding agents.** Tool-specific files such as `CLAUDE.md` should remain shims unless a tool has genuinely unique requirements.
- **Manual review and approval flows are product safety boundaries.** Do not bypass them in convenience features without explicit product/security review.
- **Local-first AI is the default.** Ollama-backed local models are the documented path; cloud AI dependencies require explicit approval.
- **Documentation is split by audience.** Agent guidance belongs in `docs/agent/`, contributor implementation docs in `docs/developer/`, and operator/user material in `docs/user/`.
