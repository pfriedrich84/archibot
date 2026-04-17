"""Tests for the CLI poll --force flag."""

from __future__ import annotations

import asyncio
import sys
from unittest.mock import AsyncMock, MagicMock, patch

import pytest


@pytest.fixture(autouse=True)
def _mock_cli_side_effects(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr("app.cli.init_db", MagicMock())
    monkeypatch.setattr("app.cli._configure_logging", MagicMock())


class _FakeLoop:
    def add_signal_handler(self, *_args, **_kwargs):
        return None

    def remove_signal_handler(self, *_args, **_kwargs):
        return None


def test_cmd_poll_passes_force() -> None:
    """cmd_poll(force=True) passes force through to poll_inbox."""
    mock_poll = AsyncMock()
    mock_paperless = MagicMock()
    mock_paperless.aclose = AsyncMock()
    mock_ollama = MagicMock()
    mock_ollama.aclose = AsyncMock()

    with (
        patch("app.cli.PaperlessClient", return_value=mock_paperless),
        patch("app.cli.OllamaClient", return_value=mock_ollama),
        patch("app.worker.poll_inbox", mock_poll),
        patch("app.cli.asyncio.get_running_loop", return_value=_FakeLoop()),
    ):
        from app.cli import cmd_poll

        asyncio.run(cmd_poll(force=True))

    mock_poll.assert_called_once_with(force=True)


def test_main_parses_force_flag_for_poll(monkeypatch: pytest.MonkeyPatch) -> None:
    """main() parses --force and passes it to cmd_poll."""
    monkeypatch.setattr(sys, "argv", ["cli", "poll", "--force"])

    mock_cmd = AsyncMock()

    with patch("app.cli.COMMANDS", {"poll": ("desc", mock_cmd)}):
        from app.cli import main

        main()

    mock_cmd.assert_called_once_with(force=True)


def test_main_poll_no_force_flag(monkeypatch: pytest.MonkeyPatch) -> None:
    """main() passes force=False for poll when --force is not provided."""
    monkeypatch.setattr(sys, "argv", ["cli", "poll"])

    mock_cmd = AsyncMock()

    with patch("app.cli.COMMANDS", {"poll": ("desc", mock_cmd)}):
        from app.cli import main

        main()

    mock_cmd.assert_called_once_with(force=False)
