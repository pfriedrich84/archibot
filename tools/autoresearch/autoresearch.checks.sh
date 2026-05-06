#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

bash -n autoresearch.sh
bash -n autoresearch.checks.sh
bash -n tools/autoresearch/autoresearch.sh
bash -n tools/autoresearch/autoresearch.checks.sh

python3 -m compileall -q app tests scripts

if command -v ruff >/dev/null 2>&1; then
  ruff check app/ tests/
elif python3 -m ruff --version >/dev/null 2>&1; then
  python3 -m ruff check app/ tests/
else
  echo "ruff not available; skipped ruff check"
fi
