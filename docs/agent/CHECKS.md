# Agent Checks

Validation commands for agents. Run the smallest relevant set before finishing code changes, and report what passed or failed.

## Python worker / CLI / MCP

From the repository root:

```bash
ruff check app/ tests/
ruff format --check app/ tests/
pytest tests/ -v
```

Use when changing `app/`, `tests/`, Python packaging, prompts used by Python code, or Python-facing configuration.

## Laravel / Inertia / Svelte

From `laravel/`:

```bash
COMPOSER_ALLOW_SUPERUSER=1 composer test
npm run lint:check
npm run format:check
npm run types:check
npm run build
```

If not running as root, use `composer test` instead of `COMPOSER_ALLOW_SUPERUSER=1 composer test`.

Use when changing Laravel, PHP, Svelte, TypeScript, frontend assets, routes, migrations, or Laravel configuration.

## Docker / CI / dependencies

Use the relevant workflow from [`WORKFLOWS.md`](WORKFLOWS.md) when changing:

- `Dockerfile`, `docker-compose.yml`, or `entrypoint.sh`
- `.github/workflows/*`
- `pyproject.toml`, `constraints.txt`, Composer dependencies, or npm dependencies
- security/audit policy files

Dependency changes must include the 3-day supply-chain age check:

```bash
python scripts/check_dependency_age.py --min-days 3
```

Docker/runtime image changes should include a local build and Grype scan when available:

```bash
docker build -t archibot-local-check .
grype archibot-local-check --only-fixed --fail-on high
```

## Documentation-only changes

Usually no test suite is required. Still verify that links, command examples, and referenced paths are correct.

```bash
python3 scripts/check_markdown_links.py
```

Use `python scripts/check_markdown_links.py` in environments where `python` points to Python 3.

Use this for README, `docs/`, `AGENTS.md`, `CLAUDE.md`, and GitHub template changes.
