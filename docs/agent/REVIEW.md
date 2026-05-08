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

## Validation

- Relevant checks from [`CHECKS.md`](CHECKS.md) are reported.
- Tests cover behavior changes and regressions.
- Dependency changes include supply-chain age and audit considerations.

## Documentation

- User-visible behavior changes update `docs/user/` or README.
- Developer/API/architecture changes update `docs/developer/`.
- Agent-relevant rules, constraints, decisions, or anti-patterns update `docs/agent/`.
