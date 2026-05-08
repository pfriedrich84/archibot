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

- Agent governance was partially present but missing several standard files for constraints, coding guidance, reviews, decisions, anti-patterns, supply chain, and definition of done.
- GitHub issue and PR templates were missing, making reproducibility and acceptance criteria less consistent.
- Documentation-only link checking existed inline in CI for agent docs but not as a reusable local script.
- `docs/developer/adr/` now has an index, but project-defining ADRs have not yet been written.
- **Low impact supply-chain drift:** CI currently invokes `aquasec/trivy:latest`. Pinning the scanner image would improve reproducibility, but this was not changed because it affects CI behavior.

## Recommended next improvements

1. Add ADRs for major established decisions: single-container deployment, local Ollama-first AI, SQLite/sqlite-vec storage, and Python/Laravel responsibility split.
2. Expand user troubleshooting docs from recurring support issues once they are known.
3. Consider adding Composer/npm dependency audit guidance if CI later adopts those ecosystem-native checks.
4. Periodically compare README, docs, CI, and Docker configuration for drift after major feature work.
