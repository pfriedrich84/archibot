#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: scripts/ci-local.sh [--pre-push|--fast|--full|--python|--laravel|--docs|--docker]

Runs local checks that mirror GitHub CI as closely as local tooling allows.

Modes:
  --fast      Python + Laravel checks from docs/agent/CHECKS.md (default)
  --full      Fast checks plus Docker build and container scans
  --pre-push  Run checks for paths changed against the upstream branch when possible
  --python    Python lint, format, tests, dependency checks, docs/Graphify checks
  --laravel   Laravel backend/frontend checks
  --docs      Markdown link and Graphify artifact checks
  --docker    Docker build plus Grype scan when tools are installed
USAGE
}

mode="fast"
if [[ $# -gt 0 ]]; then
  mode="$1"
fi

case "$mode" in
  --help|-h) usage; exit 0 ;;
  --fast) mode="fast" ;;
  --full) mode="full" ;;
  --pre-push) mode="pre-push" ;;
  --python) mode="python" ;;
  --laravel) mode="laravel" ;;
  --docs) mode="docs" ;;
  --docker) mode="docker" ;;
  *) echo "Unknown mode: $mode" >&2; usage >&2; exit 2 ;;
esac

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    echo "Install local tooling or push to a branch and wait for GitHub CI before merging." >&2
    exit 127
  fi
}

run() {
  echo "+ $*"
  "$@"
}

python_checks() {
  require_cmd python
  run python -m ruff check app/ tests/
  run python -m ruff format --check app/ tests/
  run python -m pytest tests/ -v
  run python -m pip check
  run python scripts/check_dependency_age.py --min-days 3
  run python3 scripts/check_graphify_artifacts.py
  run python3 scripts/check_markdown_links.py
}

laravel_checks() {
  require_cmd composer
  require_cmd npm
  (
    cd laravel
    if [[ "$(id -u)" == "0" ]]; then
      run env COMPOSER_ALLOW_SUPERUSER=1 composer test
    else
      run composer test
    fi
    run npm run lint:check
    run npm run format:check
    run npm run types:check
    run npm run build
  )
}

docs_checks() {
  require_cmd python3
  run python3 scripts/check_markdown_links.py
  run python3 scripts/check_graphify_artifacts.py
}

docker_checks() {
  require_cmd docker
  run docker build -t archibot-local-check .
  if command -v grype >/dev/null 2>&1; then
    run grype archibot-local-check --only-fixed --fail-on high
  else
    echo "grype not installed; skipping local Grype scan (GitHub CI still runs it)." >&2
  fi
}

changed_paths() {
  local base
  base="$(git merge-base HEAD '@{upstream}' 2>/dev/null || git merge-base HEAD origin/main 2>/dev/null || true)"
  if [[ -n "$base" ]]; then
    git diff --name-only "$base"...HEAD
  else
    git diff --name-only --cached
  fi
}

pre_push_checks() {
  local paths
  paths="$(changed_paths)"

  if [[ -z "$paths" ]]; then
    echo "No changed paths detected; running fast checks."
    python_checks
    laravel_checks
    return
  fi

  echo "Changed paths considered by pre-push gate:"
  printf '%s\n' "$paths"

  local ran=0

  if printf '%s\n' "$paths" | grep -Eq '^(app/|tests/|pyproject\.toml|constraints\.txt|prompts/|scripts/)'; then
    python_checks
    ran=1
  fi

  if printf '%s\n' "$paths" | grep -Eq '^(laravel/|\.github/workflows/|Dockerfile|docker-compose\.yml|entrypoint\.sh)'; then
    laravel_checks
    ran=1
  fi

  if printf '%s\n' "$paths" | grep -Eq '^(AGENTS\.md|CLAUDE\.md|README\.md|docs/|\.github/|\.graphify/)'; then
    docs_checks
    ran=1
  fi

  if printf '%s\n' "$paths" | grep -Eq '^(Dockerfile|docker-compose\.yml|entrypoint\.sh|\.github/workflows/)'; then
    docker_checks
    ran=1
  fi

  if [[ "$ran" == "0" ]]; then
    echo "No targeted check matched changed paths; running fast checks."
    python_checks
    laravel_checks
  fi
}

case "$mode" in
  fast)
    python_checks
    laravel_checks
    ;;
  full)
    python_checks
    laravel_checks
    docker_checks
    ;;
  pre-push) pre_push_checks ;;
  python) python_checks ;;
  laravel) laravel_checks ;;
  docs) docs_checks ;;
  docker) docker_checks ;;
esac
