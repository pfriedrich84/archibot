#!/usr/bin/env python3
"""Validate commit-safe Graphify artifacts before versioning them.

This check intentionally validates only the small artifact set useful to agents:
.graphify/GRAPH_REPORT.md, .graphify/graph.json, and .graphify/scope.json.
Runtime caches, manifests with local absolute paths, HTML exports, and other
large/local Graphify outputs should stay uncommitted.
"""

from __future__ import annotations

import json
import re
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
GRAPHIFY_DIR = ROOT / ".graphify"
COMMIT_SAFE_FILES = [
    GRAPHIFY_DIR / "GRAPH_REPORT.md",
    GRAPHIFY_DIR / "graph.json",
    GRAPHIFY_DIR / "scope.json",
]

# Graphify portable-check flags route/container literals as absolute paths.
# These values are documented application/API paths, not local workstation paths.
ALLOWED_PORTABLE_VALUES = {
    "/api/chat",
    "/chat/completions",
    "/data",
}

FORBIDDEN_PATTERNS = {
    "local absolute path": re.compile(
        r"(?:/root/|/home/|/Users/|/tmp/|/private/|/var/folders/|[A-Za-z]:\\)"
    ),
    "private key block": re.compile(r"BEGIN [A-Z ]*PRIVATE KEY"),
    "literal secret assignment": re.compile(
        r"(?i)(?:SECRET|PASSWORD|API[_-]?KEY|TOKEN|AUTHORIZATION)"
        r"\s*[:=]\s*['\"]?[^'\"\s]{8,}"
    ),
}


def _read_text(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8")
    except UnicodeDecodeError:
        return path.read_text(encoding="utf-8", errors="replace")


def check_expected_files() -> list[str]:
    errors: list[str] = []
    for path in COMMIT_SAFE_FILES:
        if not path.exists():
            errors.append(f"missing expected graphify artifact: {path.relative_to(ROOT)}")
    return errors


def check_content_patterns() -> list[str]:
    errors: list[str] = []
    for path in COMMIT_SAFE_FILES:
        if not path.exists():
            continue
        text = _read_text(path)
        for name, pattern in FORBIDDEN_PATTERNS.items():
            for match in pattern.finditer(text):
                line = text.count("\n", 0, match.start()) + 1
                value = match.group(0)
                errors.append(f"{path.relative_to(ROOT)}:{line}: {name}: {value}")
    return errors


def _portable_check_command() -> list[str] | None:
    graphify = shutil.which("graphify")
    if graphify:
        return [graphify, "portable-check", "--json", str(GRAPHIFY_DIR)]

    packaged = (
        Path.home() / ".pi/agent/git/github.com/pfriedrich84/pi-skills/node_modules/.bin/graphify"
    )
    if packaged.exists():
        return [str(packaged), "portable-check", "--json", str(GRAPHIFY_DIR)]

    npm_packaged = Path.home() / ".pi/agent/npm/node_modules/.bin/graphify"
    if npm_packaged.exists():
        return [str(npm_packaged), "portable-check", "--json", str(GRAPHIFY_DIR)]

    return None


def check_graphify_portability() -> list[str]:
    command = _portable_check_command()
    if command is None:
        print("graphify not found; skipped graphify portable-check", file=sys.stderr)
        return []

    result = subprocess.run(command, cwd=ROOT, check=False, text=True, capture_output=True)
    try:
        payload: dict[str, Any] = json.loads(result.stdout)
    except json.JSONDecodeError:
        if result.returncode == 0:
            return []
        return ["graphify portable-check failed without JSON output: " + result.stderr.strip()]

    errors = []
    for issue in payload.get("issues", []):
        path = issue.get("path")
        value = str(issue.get("value", ""))
        if path not in {"GRAPH_REPORT.md", "graph.json", "scope.json"}:
            continue
        if value in ALLOWED_PORTABLE_VALUES:
            continue
        errors.append(
            f"graphify portable-check: {path}:{issue.get('jsonPath')}: {issue.get('kind')}: {value}"
        )
    return errors


def main() -> int:
    errors = []
    errors.extend(check_expected_files())
    errors.extend(check_content_patterns())
    errors.extend(check_graphify_portability())

    if errors:
        print("Graphify artifact check failed:")
        for error in errors:
            print(f"- {error}")
        return 1

    print("Graphify artifact check passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
