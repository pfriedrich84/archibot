#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"
mkdir -p .autoresearch

cat > .autoresearch/archibot_probe.py <<'PY'
from __future__ import annotations

import fnmatch
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

EXCLUDE_DIRS = {
    ".git",
    ".autoresearch",
    ".pytest_cache",
    ".ruff_cache",
    ".venv",
    ".venv-ci",
    ".venv-ci-check",
    ".venv-depcheck",
    "archibot.egg-info",
    "node_modules",
    "vendor",
}


def read(path: str) -> str:
    p = ROOT / path
    return p.read_text(encoding="utf-8", errors="ignore") if p.is_file() else ""


def iter_files(patterns: tuple[str, ...]) -> list[Path]:
    out: list[Path] = []
    for p in ROOT.rglob("*"):
        if not p.is_file():
            continue
        rel = p.relative_to(ROOT)
        if any(part in EXCLUDE_DIRS for part in rel.parts):
            continue
        if any(fnmatch.fnmatch(str(rel), pattern) for pattern in patterns):
            out.append(rel)
    return sorted(out)


def corpus(patterns: tuple[str, ...]) -> str:
    chunks: list[str] = []
    for rel in iter_files(patterns):
        chunks.append(f"\n# FILE {rel}\n")
        chunks.append(read(str(rel)))
    return "".join(chunks)


py_app = corpus(("app/**/*.py", "app/*.py"))
py_tests = corpus(("tests/**/*.py", "tests/*.py"))
agent_docs = corpus(("docs/agent/*.md", "AGENTS.md"))
laravel_meta = corpus(("laravel/package.json", "laravel/composer.json", "laravel/vite.config.*", "laravel/eslint.config.*"))

checks: list[tuple[str, bool]] = [
    (
        "storage_path_preservation_rule_documented",
        bool(re.search(r"Do not overwrite existing Paperless storage paths", agent_docs, re.I)),
    ),
    (
        "storage_path_preservation_implemented",
        bool(re.search(r"effective_storage_path", py_app) and re.search(r"original_storage_path", py_app)),
    ),
    (
        "storage_path_preservation_tested",
        bool(re.search(r"preserves_original|preserve.*storage_path|storage_path.*authoritative", py_tests, re.I)),
    ),
    (
        "review_queue_contract_present",
        bool(re.search(r"review_suggestions|suggestion", py_app, re.I) and re.search(r"pending|approved|rejected", py_app, re.I)),
    ),
    (
        "review_queue_contract_tested",
        bool(re.search(r"review_suggestions|approve|reject|pending", py_tests, re.I)),
    ),
    (
        "tag_whitelist_gate_present",
        bool(re.search(r"tag_whitelist|whitelist", py_app, re.I)),
    ),
    (
        "tag_whitelist_gate_tested",
        bool(re.search(r"tag_whitelist|whitelist", py_tests, re.I)),
    ),
    (
        "untrusted_inbox_context_boundary_documented",
        bool(re.search(r"Do not use inbox/unreviewed documents as trusted classification context", agent_docs, re.I)),
    ),
    (
        "ocr_local_only_boundary_documented",
        bool(re.search(r"OCR corrections local|never write corrected OCR", agent_docs, re.I)),
    ),
    (
        "ocr_mode_or_requested_tag_present",
        bool(re.search(r"ocr_mode|OCR_MODE|ocr_requested|OCR_REQUESTED", py_app, re.I)),
    ),
    (
        "mcp_auth_or_token_tests_present",
        bool(re.search(r"mcp.*auth|token", py_tests, re.I)),
    ),
    (
        "python_quality_checks_documented",
        all(s in read("docs/agent/CHECKS.md") for s in ("ruff check", "ruff format", "pytest tests/")),
    ),
    (
        "frontend_quality_checks_documented",
        all(s in read("docs/agent/CHECKS.md") for s in ("npm run lint:check", "npm run types:check", "npm run build")),
    ),
    (
        "frontend_quality_scripts_available",
        all(s in laravel_meta for s in ("lint:check", "types:check", "build")),
    ),
]

failed = [name for name, ok in checks if not ok]
passed = len(checks) - len(failed)
score = 100.0 * passed / len(checks) if checks else 0.0

py_files = iter_files(("app/**/*.py", "app/*.py"))
test_files = iter_files(("tests/**/*.py", "tests/*.py"))
frontend_files = iter_files(("laravel/resources/**/*.svelte", "laravel/resources/**/*.ts", "laravel/resources/**/*.js"))

print(f"checks={len(checks)} failed={len(failed)} passed={passed}")
if failed:
    print("failed_invariants=" + ",".join(failed))

print(f"METRIC archibot_agent_readiness_score={score:.6f}")
print(f"METRIC failed_invariants={len(failed)}")
print(f"METRIC python_files={len(py_files)}")
print(f"METRIC python_test_files={len(test_files)}")
print(f"METRIC frontend_source_files={len(frontend_files)}")
print(f"METRIC safety_contract_checks={len(checks)}")
print(f"METRIC safety_contract_passed={passed}")
PY

start=$(date +%s)
python3 .autoresearch/archibot_probe.py
end=$(date +%s)

echo "METRIC unit_seconds=$((end - start))"
