# Agent Tooling Policy

Approved tooling for coding agents working on ArchiBot.

## Active default tooling

Use the following tooling by default when it is available and relevant:

1. GitHub MCP for repository context.
2. Context7 MCP for current dependency and framework documentation.
3. Project-native quality checks for linting, type checking, tests, builds, Docker validation, and supply-chain checks.
4. Graphify for optional repository knowledge-graph orientation and blast-radius analysis when `.graphify/` artifacts are useful.

Serena MCP is optional and must only be used when there is a strong, concrete reason.

ABAP MCP and `abaplint` are not active because this repository currently contains no ABAP code.

## GitHub MCP

Use GitHub MCP for repository-aware work.

Use it to inspect:

- repository structure
- existing files and conventions
- issues
- pull requests
- changed files
- GitHub Actions
- CI failures

Rules:

- Do not make broad repository changes without checking the current repository structure first.
- Prefer small, focused changes.
- Respect existing naming, layout, architecture, and conventions.
- Do not rewrite unrelated files.
- Do not use GitHub write actions unless the requested task clearly requires repository changes.

## Context7 MCP

Use Context7 MCP for up-to-date, version-specific documentation.

Use it before making assumptions about:

- framework APIs
- library behavior
- breaking changes
- configuration syntax
- Docker/deployment examples
- authentication libraries
- frontend/backend integration patterns

Rules:

- Do not rely on outdated model memory for dependency-specific behavior when Context7 is available.
- Prefer version-aware documentation over generic examples.
- Keep implementation decisions aligned with the dependencies and versions used by this repository.

## Graphify

Use Graphify when the committed knowledge graph can speed up architecture orientation, file-relationship questions, or blast-radius analysis. Start with [`.graphify/GRAPH_REPORT.md`](../../.graphify/GRAPH_REPORT.md) or Graphify query/review commands; do not dump raw `.graphify/graph.json` into chat.

Rules:

- Treat Graphify output as an analysis aid, not source of truth; confirm important conclusions against source files before editing.
- Commit only `.graphify/GRAPH_REPORT.md`, `.graphify/graph.json`, and `.graphify/scope.json` unless the maintainer explicitly approves more artifacts.
- Before committing graph artifacts, run `python3 scripts/check_graphify_artifacts.py` from the repository root.
- Graphify-only commits under `.graphify/**` are intentionally ignored by CI push triggers so refreshing the agent graph does not build or publish Docker images.

## Project-native quality checks

Before completing code changes, run the relevant checks from [`CHECKS.md`](CHECKS.md).

Examples:

- Python: `ruff check`, `ruff format --check`, `pytest`
- Laravel/PHP/Svelte/TypeScript: Composer tests, lint checks, format checks, type checks, and build checks defined in `laravel/`
- Docker/Compose: local build, Docker validation, and image scans where relevant
- Dependencies: supply-chain age checks and configured dependency audits
- Documentation: Markdown link checks for docs-only changes

Rules:

- Use the checks already defined by the repository first.
- Do not invent a new toolchain unless there is a clear reason.
- If a check cannot be run, explain why and state the exact command the user should run.
- Do not ignore failing checks unless the reason is documented.

## Optional: Serena MCP

Serena MCP is not part of the default workflow.

Use Serena only when there is a strong, concrete case, such as:

- large refactoring across many files
- symbol/reference search is needed
- call chains need to be understood
- text search would be too risky or imprecise
- the task affects core architecture

Rules:

- Do not use Serena for small edits.
- Do not use Serena just because it is available.
- Prefer normal repository inspection for simple changes.
- Document why Serena was useful if it influenced the solution.

## Explicitly not active

The following tools are not active unless the repository later requires them:

- ABAP MCP
- `abaplint`
- SAP/RAP/CDS-specific checks

If ABAP, RAP, CDS, AMDP, or SAP transport code is added later, ABAP-specific tooling must be reconsidered.
