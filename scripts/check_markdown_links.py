#!/usr/bin/env python3
"""Check local Markdown links without external dependencies."""

from __future__ import annotations

import re
import sys
from pathlib import Path
from urllib.parse import unquote

ROOT = Path(__file__).resolve().parents[1]
SKIP_DIRS = {".git", ".venv", ".venv-ci", ".venv-ci-check", ".venv-depcheck", "node_modules", "vendor", ".pytest_cache", ".ruff_cache"}
LINK_RE = re.compile(r"(?<!!)\[[^\]]+\]\(([^)\s]+)(?:\s+\"[^\"]*\")?\)")


def iter_markdown_files() -> list[Path]:
    files: list[Path] = []
    for path in ROOT.rglob("*.md"):
        if any(part in SKIP_DIRS for part in path.relative_to(ROOT).parts):
            continue
        files.append(path)
    return sorted(files)


def is_external(target: str) -> bool:
    return (
        "://" in target
        or target.startswith("mailto:")
        or target.startswith("tel:")
        or target.startswith("#")
    )


def main() -> int:
    missing: list[tuple[str, str]] = []
    for file in iter_markdown_files():
        text = file.read_text(encoding="utf-8")
        for match in LINK_RE.finditer(text):
            target = match.group(1).strip()
            if is_external(target):
                continue
            target_path = unquote(target.split("#", 1)[0])
            if not target_path:
                continue
            candidate = (file.parent / target_path).resolve()
            try:
                candidate.relative_to(ROOT)
            except ValueError:
                missing.append((str(file.relative_to(ROOT)), target))
                continue
            if not candidate.exists():
                missing.append((str(file.relative_to(ROOT)), target))

    if missing:
        print("Missing local Markdown links:")
        for file, target in missing:
            print(f"  {file}: {target}")
        return 1
    print("All local Markdown links OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
