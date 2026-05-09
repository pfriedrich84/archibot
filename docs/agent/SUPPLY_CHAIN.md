# Agent Supply-Chain Guidance

## Existing posture

ArchiBot already includes multiple supply-chain controls:

- Pinned Python transitive constraints in `constraints.txt`, including the event-driven Dramatiq/RabbitMQ and PostgreSQL/pgvector runtime dependencies.
- Python dependency age check: `scripts/check_dependency_age.py --min-days 3`.
- Known vulnerability allowlist file: `.pip-audit-known-vulnerabilities`.
- CI security/audit steps for pip-audit, Docker build, Grype, and Trivy.
- Docker linting with Hadolint.

## Rules for dependency changes

- Prefer existing dependencies over adding new packages.
- Use stable releases; Python dependency changes must pass the 3-day age check unless there is an explicit security exception.
- Document security exceptions in `.dependency-age-allowlist` with a reason and expiry when applicable.
- Update lock/constraint files deliberately and validate the resulting dependency graph. Event-driven runtime additions must keep explicit upper bounds in both `pyproject.toml` and `constraints.txt`.
- Do not introduce `latest` image tags for runtime dependencies.

## Validation commands

Python dependency changes:

```bash
python scripts/check_dependency_age.py --min-days 3
python -m pip check
```

Laravel/frontend dependency changes should use the existing lockfile workflow from `laravel/package-lock.json` and Composer lockfile validation via existing Laravel checks.

Docker/runtime image changes should include when available:

```bash
docker build -t archibot-local-check .
grype archibot-local-check --only-fixed --fail-on high
```

If a scanner is unavailable locally, say so and rely on CI for that specific scan.
