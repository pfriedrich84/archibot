from __future__ import annotations

import sys
from unittest.mock import MagicMock

from app import cli


def test_process_document_and_force_delegate_to_laravel(monkeypatch) -> None:
    monkeypatch.setattr(cli, "_configure_logging", MagicMock())
    invoke = MagicMock()
    monkeypatch.setattr(cli, "cmd_laravel_maintenance", invoke)

    monkeypatch.setattr(sys, "argv", ["archibot", "process-doc", "224"])
    cli.main()
    invoke.assert_called_with("process_document", force=False, document_id=224)

    monkeypatch.setattr(sys, "argv", ["archibot", "process-doc", "224", "--force"])
    cli.main()
    invoke.assert_called_with("process_document", force=True, document_id=224)
