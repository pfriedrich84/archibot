# Agent Review Guidance

Use this checklist when reviewing ArchiBot changes.

## Product safety

- Existing Paperless storage paths are not overwritten.
- New entities still use approval/whitelist flows.
- Manual review remains the default safety path.
- Inbox/unreviewed documents are not used as trusted context.
- OCR corrections stay local.

## Implementation quality

- Python/Laravel responsibilities remain separated.
- CLI and GUI behavior stay consistent for shared job/status semantics.
- User-facing UI resolves labels instead of exposing raw IDs.
- Error handling degrades gracefully for optional integrations.

## Validation and evidence

- Relevant checks from [`CHECKS.md`](CHECKS.md) are reported using the states, identity, and freshness contract in [`CONTEXT_AND_EVIDENCE.md`](CONTEXT_AND_EVIDENCE.md).
- Exit code, executed scope, counts, skips, warnings, and truncation were inspected; zero exit alone was not treated as approval.
- Evidence is current for the final reviewed patch; affected earlier results were marked `STALE` and rerun.
- Delegated review scopes and findings are complete and reconciled; missing coverage is `INCONCLUSIVE`, not “no findings.”
- Tests cover behavior changes and regressions.
- Dependency changes include supply-chain age and audit considerations.

## Documentation

- User-visible behavior changes update `docs/user/` or README.
- Developer/API/architecture changes update `docs/developer/`.
- Agent-relevant rules, constraints, decisions, or anti-patterns update `docs/agent/`.
