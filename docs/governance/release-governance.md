# Release Governance

ArchiBot releases are Docker-first and should remain traceable to a reviewed Git commit.

## Trusted release sources

- `main` is the trusted release branch for the `latest` container tag.
- The Docker publish workflow builds from the CI-tested commit SHA after the `CI` workflow succeeds.
- SHA-derived image tags are preferred for rollback and operational traceability.

## Required checks before publishing or announcing a release

Use the relevant checks from `docs/agent/CHECKS.md`. For release-impacting changes, the expected baseline is:

- Python lint, format, tests, dependency compatibility, security audit, and dependency-age checks.
- Laravel backend tests, frontend lint, format, type check, and build.
- Docker build plus Grype and Trivy scans.
- Documentation link checks for docs-only or governance-heavy changes.

If any check is skipped locally, note why and rely on CI only for that specific unavailable capability.

## Release review expectations

Before merging or tagging release-impacting work, reviewers should confirm:

- Paperless write paths still require explicit approval or configured safe automation.
- New Paperless entities still go through approval/whitelist flows.
- Admin-only job-control actions remain guarded by `is_admin()`.
- CLI and Laravel UI behavior remain aligned for shared workflows.
- Runtime state still uses PostgreSQL/pgvector as the durable source of truth for migrated flows.
- Secrets, tokens, full document contents, full OCR text, and full prompts are not logged or committed.
- User-facing UI resolves Paperless names/labels and does not expose bare numeric IDs or raw JSON as the primary display.
- Dependency and Docker-image changes follow `docs/agent/SUPPLY_CHAIN.md`.
- Trust-boundary changes are reflected in `docs/governance/trust-boundaries.md` when durable.

## Rollback awareness

- Prefer deploying SHA-tagged images when operational rollback matters.
- Keep persistent volumes intact during rollback unless an operator explicitly approves destructive reset.
- Schema and data migrations should document backward-compatibility and recovery expectations when they are not obviously reversible.
- If a release changes worker/job-control semantics, verify dashboard, `/healthz`, and the relevant worker or pipeline pages after deployment.

## Provenance roadmap

Current traceability is CI-tested commit -> Docker image tag -> GHCR publication.

Future hardening options:

- signed container images;
- generated SBOMs attached to releases;
- signed provenance attestations for GitHub Actions builds;
- release notes that list migration, rollback, and trust-boundary impacts.
