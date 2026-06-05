# Agent Checks

Validation commands for agents. Run the smallest relevant set before finishing code changes, and report what passed or failed.

## Local CI parity and pre-push gate

Use the local CI parity wrapper when practical:

```bash
scripts/ci-local.sh --fast
```

For release, Docker, workflow, or dependency-sensitive changes, use the fuller gate:

```bash
scripts/ci-local.sh --full
```

Install the repository pre-push hook once per clone to prevent obvious CI regressions from reaching `main`:

```bash
scripts/install-git-hooks.sh
```

The hook chains any existing local pre-push hook via `.git/hooks/pre-push.archibot-previous`, then runs `scripts/ci-local.sh --pre-push`. The pre-push mode reads Git's pushed refs from stdin and selects checks from the files changed by the commits actually being pushed. If required local tooling is unavailable, push to a branch and wait for GitHub CI before merging to `main`.

## Python worker / CLI / MCP

From the repository root:

```bash
ruff check app/ tests/
ruff format --check app/ tests/
pytest tests/ -v
```

Use when changing `app/`, `tests/`, Python packaging, prompts used by Python code, or Python-facing configuration.

When a change affects worker jobs or CLI commands launched from Laravel, also validate the actual JSON-file CLI contract or the narrow command path that regressed. Capture stdout/stderr and verify routine setup chatter does not leak into user-visible logs. In particular, embedding/poll/reindex commands must not print legacy SQLite lifecycle messages such as `initializing database path=/data/classifier.db` or `database ready` during normal successful runs.

For regressions involving progress/readiness counters, add or update tests that assert both sides of the contract:

- the Python result/progress payload (`done`, `total`, `failed`, returned counts);
- the Laravel-ingested snapshot or dashboard values that operators see.

If UI and CLI expose the same operation, validate that they use the same durable source of truth rather than separate legacy state.

## Laravel / Inertia / Svelte

For UI changes or bugs reached through a button/control, validate each affected button path before committing. Prefer a focused Laravel feature test or browser/component test that proves the route/action/job dispatch and the user-visible success/failure state. If automation is not available, perform and report an explicit manual smoke test for each affected control.

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

## Graphify knowledge graph artifacts

Commit only the small agent-useful Graphify artifact set:

- `.graphify/GRAPH_REPORT.md`
- `.graphify/graph.json`
- `.graphify/scope.json`

Do not commit `.graphify/cache/`, manifests with local absolute paths, HTML exports, or other runtime state unless explicitly reviewed and approved. Before committing graph artifacts, run:

```bash
python3 scripts/check_graphify_artifacts.py
```

Graphify-only commits under `.graphify/**` are ignored by CI push triggers so refreshing the agent graph does not build or publish Docker images.

## Regression-focused validation before handoff

Before finishing a bug fix, record the exact symptom from the report and run at least one check that would have failed before the fix. Prefer a focused automated test. If the regression was visible only in runtime logs or a worker-job detail page, add a regression assertion for the emitted result/progress/log text, or manually run the command and state the forbidden/expected output you checked.

For event-driven pipeline work, explicitly check for stale legacy paths:

```bash
rg -n "classifier\.db|doc_embedding_meta|audit_log|worker_jobs|_has_embedding_index|init_db" app laravel tests docs
```

Use the grep result to confirm that any touched path still follows the current PostgreSQL/pgvector/commands/pipeline contract, or document why a legacy compatibility path remains intentional.

## Documentation-only changes

Usually no test suite is required. Still verify that links, command examples, and referenced paths are correct.

```bash
python3 scripts/check_markdown_links.py
```

Use `python scripts/check_markdown_links.py` in environments where `python` points to Python 3.

Use this for README, `docs/`, `AGENTS.md`, `CLAUDE.md`, and GitHub template changes.
