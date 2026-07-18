from __future__ import annotations

import sys
from unittest.mock import MagicMock

from app import cli


def test_poll_and_force_poll_delegate_to_laravel(monkeypatch) -> None:
    monkeypatch.setattr(cli, "_configure_logging", MagicMock())
    invoke = MagicMock()
    monkeypatch.setattr(cli, "cmd_laravel_maintenance", invoke)

    monkeypatch.setattr(sys, "argv", ["archibot", "poll"])
    cli.main()
    monkeypatch.setattr(sys, "argv", ["archibot", "poll", "--force"])
    cli.main()

    assert invoke.call_args_list == [
        __import__("unittest.mock").mock.call("poll", force=False, limit=None),
        __import__("unittest.mock").mock.call("poll", force=True, limit=None),
    ]
