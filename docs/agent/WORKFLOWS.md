# Agent Workflows

Reusable, tool-neutral workflows for common repository tasks.

## Standard change workflow

1. Read [`RULES.md`](RULES.md) and the relevant project docs.
2. Keep the change small and reviewable.
3. Update tests and docs when behavior changes.
4. Run the relevant checks from [`CHECKS.md`](CHECKS.md).
5. Summarize changed files, validation results, and any follow-up work.

## Local CI simulation

Use this when a change touches multiple subsystems or CI configuration.

### Python

From the repository root:

1. `ruff check app/ tests/`
2. `ruff format --check app/ tests/`
3. `pytest tests/ -v`
4. `pip check`
5. `pip-audit --skip-editable` with ignores from `.pip-audit-known-vulnerabilities`
6. `python scripts/check_dependency_age.py --min-days 3`
7. `archibot --help`
8. `python -m app.cli --help`
9. `python -c "import app.cli; import app.mcp_server"`
10. `python -c "from app.db import init_db; init_db()"`
11. `python -c "from app.pipeline.classifier import _load_system_prompt; assert len(_load_system_prompt()) > 100"`

### Laravel / frontend

From `laravel/`:

1. `COMPOSER_ALLOW_SUPERUSER=1 composer test` when running as root; otherwise `composer test`
2. `npm run lint:check`
3. `npm run format:check`
4. `npm run types:check`
5. `npm run build`

If one check fails, continue with independent checks where practical so the final report is complete.

## Dependency update workflow

Use the 3-day supply-chain rule for Python dependencies.

1. Determine the target version if none was provided:

   ```bash
   curl -s https://pypi.org/pypi/<package>/json | python3 -c "import sys,json; print(json.load(sys.stdin)['info']['version'])"
   ```

2. Check the upload date:

   ```bash
   curl -s https://pypi.org/pypi/<package>/<version>/json | python3 -c "import sys,json; print(json.load(sys.stdin)['urls'][0]['upload_time'])"
   ```

3. If the release is younger than 3 days, stop unless it is a CVE/security fix. For security exceptions, document the CVE and expiry in `.dependency-age-allowlist`.
4. Raise the bound or pin:
   - Direct Python dependency: `pyproject.toml`
   - Transitive Python dependency: `constraints.txt`
5. Install: `pip install -c constraints.txt -e ".[dev]"`
6. Run:
   - `ruff check app/ tests/`
   - `ruff format --check app/ tests/`
   - `pytest tests/ -v`
   - `python scripts/check_dependency_age.py --min-days 3`
7. Summarize changed dependency files and validation results.

Do not commit or push unless the user explicitly asks.
