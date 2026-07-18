from __future__ import annotations

import sys
from unittest.mock import MagicMock, call

from app import cli


def test_reindex_variants_delegate_to_laravel(monkeypatch) -> None:
    monkeypatch.setattr(cli, "_configure_logging", MagicMock())
    invoke = MagicMock()
    monkeypatch.setattr(cli, "cmd_laravel_maintenance", invoke)

    cases = [
        (["reindex"], call("reindex", force=False, limit=None)),
        (["reindex-ocr", "--force"], call("reindex_ocr", force=True, limit=None)),
        (["reindex-embed"], call("reindex_embed", force=False, limit=None)),
    ]
    for args, expected in cases:
        monkeypatch.setattr(sys, "argv", ["archibot", *args])
        cli.main()
        assert invoke.call_args == expected
