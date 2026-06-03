# Agent Definition of Done

A change is ready for handoff when:

- The implementation addresses the requested scope without unrelated churn.
- ArchiBot safety invariants from [`RULES.md`](RULES.md) remain intact.
- Relevant tests, lint, type, build, or docs checks from [`CHECKS.md`](CHECKS.md) were run and reported.
- Bug fixes include a regression-focused validation that would have caught the reported failure, including stdout/stderr or worker-job log assertions when the symptom was noisy or misleading runtime output.
- User-facing behavior changes are documented in README or `docs/user/`.
- Developer-facing implementation changes are documented in `docs/developer/` when needed.
- Cross-runtime changes verify Python CLI/actor output and Laravel-ingested dashboard/worker state against the same durable source of truth.
- Agent-relevant new constraints, decisions, or anti-patterns are recorded in `docs/agent/` when durable.
- Secrets and private document data were not read, printed, or persisted.
- Remaining TODOs, skipped checks, and follow-ups are explicit in the final summary.
- Before pushing to a protected or release branch, the local pre-push gate or equivalent GitHub CI checks have passed.
- The user is told whether changes are uncommitted; commits and pushes happen only on explicit request.
