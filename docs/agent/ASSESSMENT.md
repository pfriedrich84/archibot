# Repository Governance Assessment

Date: 2026-05-27

## Maturity summary

- Documentation maturity: **operational**
- Operational maturity: **operational with migration debt**
- Validation maturity: **operational**
- Supply-chain maturity: **operational**
- AI-agent readiness: **operational**
- Governance consistency: **operational with known follow-ups**

## Current canonical entrypoints

- `AGENTS.md` is the root operating contract for coding agents.
- `docs/agent/` contains durable agent rules, safety, checks, memory, anti-patterns, supply-chain rules, and definition of done.
- `docs/README.md` is the human documentation index.
- `docs/decisions/` contains accepted event-driven architecture ADRs.
- `docs/governance/` contains repository workflow, review, trust-boundary, and release-governance guidance.

## Documentation inventory and roles

| Area | Role | Assessment |
| --- | --- | --- |
| `AGENTS.md` | Canonical operating contract | Clear entrypoint with reading order and event-driven invariants. |
| `docs/agent/` | Agent governance | Strong coverage of rules, constraints, safety, checks, supply chain, memory, anti-patterns, autoresearch, and definition of done. |
| `docs/decisions/` | Accepted ADRs | Strong event-driven migration decisions exist for Laravel queue transport, PostgreSQL/pgvector, webhooks, polling, retries, observability, progress, authorization, and temporary worker jobs. |
| `docs/architecture/` | Architecture contracts | Good detailed contracts for migration invariants and operator-visible behavior. |
| `docs/developer/` | Implementation reference | Useful current-state references; some pages intentionally describe temporary `worker_jobs` behavior. |
| `docs/user/` | Operator/user docs | Covers installation, configuration, deployment, webhooks, auth, and workflow. |
| `docs/governance/` | Collaboration and review governance | Now includes repository governance, trust boundaries, release governance, agent workflow, and review checklist. |
| `.github/` | Collaboration and automation | CI, publish workflow, issue/PR templates, and CODEOWNERS are present. |
| `Dockerfile`, `docker-compose.yml`, manifests | Runtime and supply-chain contracts | Docker-first runtime is clear; constraints and CI scanners enforce Python/container supply-chain checks. |

## Strengths

- Clear root `AGENTS.md` and modular agent docs.
- Accepted ADRs cover the main event-driven migration constraints.
- Product safety boundaries are documented around review queues, whitelists, Paperless writes, raw IDs, and metadata display.
- CI covers Python lint/tests/security, Laravel tests/frontend checks, Docker build, Hadolint, Grype, and Trivy.
- Python supply-chain posture is strong: constraints, pip-audit, known-vulnerability allowlist, and dependency-age checks.
- Trust boundaries and release expectations are now explicitly documented.
- Committed Graphify artifacts provide an agent-readable knowledge graph while guardrails keep local caches/manifests out of Git and prevent Graphify-only pushes from building Docker images.
- CODEOWNERS now gives GitHub a default owner for high-risk governance and automation surfaces.

## Drift and findings from the 2026-05-27 scan

- **Resolved:** `AGENTS.md` and related governance docs now describe committed Graphify artifacts, the required artifact safety check, and the rule that Graphify-only commits should not trigger Docker image builds.

## Drift and findings from the 2026-05-23 scan

- **Resolved:** Dockerfile Python dependency install bounds had drifted behind `pyproject.toml` / `constraints.txt` for `sqlite-vec`, `mcp`, `pymupdf`, `pydantic-settings`, and `structlog`. The Dockerfile bounds were updated to match current repository constraints.
- **Resolved:** Trust-boundary documentation was implicit across safety, supply-chain, user, architecture, and workflow docs. It is now centralized in `docs/governance/trust-boundaries.md`.
- **Resolved:** Release and rollback expectations were implicit in CI and publish workflow. They are now centralized in `docs/governance/release-governance.md`.
- **Known follow-up:** CI invokes `aquasec/trivy:latest`. This is a scanner/tooling image rather than an ArchiBot runtime dependency, but pinning it would improve reproducibility.
- **Known follow-up:** Composer/npm ecosystem-native security audits are not first-class CI gates yet. Add them only with a clear false-positive/noise policy.
- **Known follow-up:** Formal incident-response and credential-rotation runbooks remain lightweight.
- **Known follow-up:** `docs/developer/adr/` is an older ADR placeholder while accepted ADRs live in `docs/decisions/`. Keep it as a pointer or remove it in a separate cleanup if it becomes confusing.

## Missing or partial governance topics

- Incident response: containment, audit preservation, credential rotation, and communication expectations.
- Provenance hardening: signed images, SBOMs, and attestations.
- Policy-as-code: workflow permission checks, dependency-review policy, and architecture invariant checks could become automated later.
- More granular CODEOWNERS can be added when additional maintainers or ownership boundaries emerge.

## Recommended next improvements

1. Pin the Trivy scanner image in CI after checking the current upstream release and confirming scanner behavior.
2. Add an incident response / credential rotation runbook before broader multi-user or public-service operation.
3. Add SBOM/provenance planning to the publish workflow once releases stabilize.
4. Decide whether `docs/developer/adr/README.md` should remain as a pointer or be replaced by a redirect to `docs/decisions/`.
5. Evaluate Composer/npm audit commands in CI separately from this governance pass.
