# Repository Governance Assessment

Date: 2026-05-08

## Maturity summary

- Documentation maturity: **operational**
- Operational maturity: **developing to operational**
- Validation maturity: **operational**
- Supply-chain maturity: **operational**
- AI-agent readiness: **operational**
- Governance consistency: **developing**

## Strengths

- Clear README and grouped documentation under `docs/user`, `docs/developer`, and `docs/agent`.
- Existing CI covers Python lint/tests, Laravel tests/frontend checks, Docker build, and security scanning.
- Supply-chain posture is stronger than typical small projects: constraints, pip-audit, dependency-age checks, Grype, and Trivy are present.
- Product safety boundaries are documented around review queues, whitelists, and Paperless storage paths.

## Governance debt and drift

- Standard agent governance files are now present and linked from `AGENTS.md` and `docs/README.md`.
- GitHub issue and pull request templates are now present for reproducible collaboration.
- Documentation-only link checking is available as `scripts/check_markdown_links.py`.
- `docs/developer/adr/` has an index, but project-defining ADRs have not yet been written.
- **Low impact supply-chain drift:** CI currently invokes `aquasec/trivy:latest`. Pinning the scanner image would improve reproducibility, but this was not changed because it affects CI behavior.

## Recommended next improvements

1. Add ADRs for major established decisions: single-container deployment, local Ollama-first AI, PostgreSQL/pgvector storage, and Python/Laravel responsibility split.
2. Expand user troubleshooting docs from recurring support issues once they are known.
3. Consider adding Composer/npm dependency audit guidance if CI later adopts those ecosystem-native checks.
4. Periodically compare README, docs, CI, and Docker configuration for drift after major feature work.
