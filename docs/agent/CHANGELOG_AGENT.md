# Agent Governance Changelog

## 2026-07-17

- Replaced the broad startup reading list with task-triggered context routing so agents load only applicable governance and project documents.
- Condensed duplicated architecture and source-of-truth lists in `AGENTS.md` while preserving links to canonical detail.

## 2026-07-14

- Added a canonical context and evidence discipline covering active context, revision-bound evidence, safe raw artifacts, explicit result states, freshness, truncation, delegation, compaction, and interruption recovery.
- Linked validation, review, workflow, definition-of-done, governance, trust-boundary, and PR guidance to the shared evidence contract instead of duplicating it.
- Aligned supporting commit/push guidance with the canonical root `AGENTS.md` policy.
- Updated the governing migration prompt and review checklist to use ADR-0015 Laravel database queue terminology instead of superseded Absurd transport instructions.

## 2026-05-23

- Added explicit governance docs for trust boundaries and release/rollback expectations.
- Added CODEOWNERS for default repository ownership and high-risk governance/automation surfaces.
- Updated the governance assessment after an active scan of docs, CI, Docker, dependency manifests, and workflow files.
- Recorded the rule that `pyproject.toml`, `constraints.txt`, and Dockerfile Python install bounds should stay aligned.

## 2026-05-13

- Updated governance, user, and developer docs to reflect local OpenAI-compatible and Ollama-compatible provider support.
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
