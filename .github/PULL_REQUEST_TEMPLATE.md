## Summary

- 

## Type of change

- [ ] Bug fix
- [ ] Feature
- [ ] Documentation/governance
- [ ] Refactor/maintenance
- [ ] Dependency/runtime

## Safety checklist

- [ ] Paperless storage paths are not overwritten.
- [ ] Review/approval/whitelist flows remain intact.
- [ ] Secrets and private document data are not included.
- [ ] UI shows user-friendly labels instead of raw IDs/JSON where relevant.
- [ ] New or changed trust boundaries are documented in `docs/governance/trust-boundaries.md`.
- [ ] Release, rollback, migration, or provenance impact is noted when relevant.

## Validation and evidence

Candidate identity:

- Clean tree / committed candidate: commit SHA.
- Dirty tree: base `HEAD` plus a content digest/manifest covering the contents of all relevant staged, unstaged, and untracked files, or an equivalently immutable safe patch artifact.

Use the result states from `docs/agent/CONTEXT_AND_EVIDENCE.md` and record current evidence after the last material edit:

- Local/targeted checks state (choose one): `<PASS | PASS_WITH_WARNINGS | FAIL | INCONCLUSIVE | STALE>`
- GitHub CI state for this candidate (choose one): `<PASS | PASS_WITH_WARNINGS | FAIL | INCONCLUSIVE | STALE | not run>`
- [ ] Exit codes, executed scope, counts, skips, warnings, and truncation were inspected
- [ ] Required evidence is current; affected stale checks were rerun

Commands, concise results, counts, warnings, and skipped/incomplete coverage:

-

## Notes for reviewers

- [ ] Delegated/reviewer scopes are complete, and findings are dispositioned or marked `INCONCLUSIVE`

Risks, assumptions, skipped checks, incomplete evidence, or follow-ups:
