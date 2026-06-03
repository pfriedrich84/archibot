# Agent Supply-Chain Guidance

## Existing posture

ArchiBot already includes multiple supply-chain controls:

- Pinned Python transitive constraints in `constraints.txt`, including the event-driven Dramatiq/RabbitMQ and PostgreSQL/pgvector runtime dependencies.
- Python dependency age check: `scripts/check_dependency_age.py --min-days 3`.
- Known vulnerability allowlist file: `.pip-audit-known-vulnerabilities`.
- CI security/audit steps for pip-audit, Docker build, Grype, and Trivy.
- Docker linting with Hadolint.
- Dependabot update configuration in [`.github/dependabot.yml`](../../.github/dependabot.yml) for GitHub Actions, Python, Laravel Composer, Laravel frontend, and Docker dependencies.
- Trust-boundary documentation in [`../governance/trust-boundaries.md`](../governance/trust-boundaries.md).
- Release and rollback expectations in [`../governance/release-governance.md`](../governance/release-governance.md).

## Rules for dependency changes

- Prefer existing dependencies over adding new packages.
- Use stable releases; Python dependency changes must pass the 3-day age check unless there is an explicit security exception.
- Document security exceptions in `.dependency-age-allowlist` with a reason and expiry when applicable.
- Update lock/constraint files deliberately and validate the resulting dependency graph. Event-driven runtime additions must keep explicit upper bounds in both `pyproject.toml` and `constraints.txt`.
- Treat Dependabot PRs as dependency changes: review release notes, preserve lockfile/constraint alignment, run the relevant checks, and do not auto-merge changes that affect runtime, CI, Docker images, or security-sensitive libraries without human review.
- Do not introduce `latest` image tags for runtime dependencies.

## External documentation policy

- For dependency-sensitive code or configuration changes, use current external documentation before implementation. Prefer Context7 for public library/framework/SDK/API/CLI documentation when it is available.
- If Context7 does not return useful documentation, fall back to official docs, release notes, upstream README files, or source code and state that fallback in the final summary.
- Verify breaking changes, deprecations, payload formats, generated-client behavior, and CLI flags against current docs instead of stale model memory.
- This policy applies especially to Laravel/Svelte/Inertia, Python libraries such as httpx/Dramatiq/SQLAlchemy, Docker/Compose, Paperless APIs, and OpenAI-compatible provider payloads.

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

When changing dependency bounds, keep `pyproject.toml`, `constraints.txt`, and the dependency install list in `Dockerfile` aligned unless there is a documented reason for a narrower runtime bound.

If a scanner is unavailable locally, say so and rely on CI for that specific scan.
