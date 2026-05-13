# Agent Governance Changelog

## 2026-05-13

- Updated governance, user, and developer docs to reflect local OpenAI-compatible/LiteLLM provider support alongside native Ollama.
- Recorded the non-secret embedding compatibility rule that OpenAI-compatible embedding requests must include `encoding_format: "float"` and must not send null.
- Added `docs/agent/TOOLING.md` to the documentation index.
- Added explicit Context7/current-external-documentation guidance to agent coding, workflow, and supply-chain docs.
- Refreshed the governance assessment after resolving provider-documentation drift.

## 2026-05-08

- Normalized repository governance around `AGENTS.md` as the tool-neutral entry point.
- Added standard agent governance files for constraints, coding, review, supply chain, memory, decisions, anti-patterns, definition of done, assessment, and governance changelog.
- Added GitHub issue and pull request templates for reproducible agent/human collaboration.
- Added a reusable Markdown local-link checker under `scripts/` and documented it in agent checks.
- Added an ADR directory index under `docs/developer/adr/`.
- Refreshed `ASSESSMENT.md` after governance files, templates, and link checking were established.
