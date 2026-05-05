from __future__ import annotations

from types import SimpleNamespace

import pytest

from app.config import settings
from app.mcp_tools import _auth


class DummyCompletedProcess:
    def __init__(self, returncode: int, stdout: str, stderr: str = "") -> None:
        self.returncode = returncode
        self.stdout = stdout
        self.stderr = stderr


def make_ctx(headers: dict[str, str] | None = None, arguments: dict[str, str] | None = None):
    meta = SimpleNamespace(headers=headers or {}, arguments=arguments or {})
    return SimpleNamespace(request_context=SimpleNamespace(meta=meta))


@pytest.fixture(autouse=True)
def reset_mcp_auth_settings(monkeypatch):
    monkeypatch.setattr(settings, "mcp_api_key", "")
    monkeypatch.setattr(settings, "mcp_laravel_auth_enabled", False)
    monkeypatch.setattr(settings, "mcp_laravel_path", "laravel")
    monkeypatch.setattr(settings, "mcp_laravel_php_binary", "php")


def test_no_auth_configured_allows_stdio_development():
    assert _auth.check_api_key(make_ctx()) is None


def test_legacy_static_api_key_still_works_from_header(monkeypatch):
    monkeypatch.setattr(settings, "mcp_api_key", "legacy-secret")

    assert _auth.check_api_key(make_ctx(headers={"x-api-key": "legacy-secret"})) is None

    with pytest.raises(ValueError, match="Invalid or missing API key"):
        _auth.check_api_key(make_ctx(headers={"x-api-key": "wrong"}))


def test_laravel_mcp_token_verifier_accepts_bearer_token_and_attaches_identity(
    monkeypatch, tmp_path
):
    laravel_path = tmp_path / "laravel"
    laravel_path.mkdir()
    calls = []

    def fake_run(command, cwd, check, capture_output, text, timeout):
        calls.append(
            {
                "command": command,
                "cwd": cwd,
                "check": check,
                "capture_output": capture_output,
                "text": text,
                "timeout": timeout,
            }
        )
        return DummyCompletedProcess(
            0,
            '{"ok":true,"user":{"id":1,"paperless_user_id":42,"paperless_username":"ada",'
            '"is_admin":true},"token":{"id":5,"name":"Claude Desktop"},'
            '"permissions":{"mcp_write_enabled":false}}\n',
        )

    monkeypatch.setattr(settings, "mcp_laravel_auth_enabled", True)
    monkeypatch.setattr(settings, "mcp_laravel_path", str(laravel_path))
    monkeypatch.setattr(settings, "mcp_laravel_php_binary", "php8.4")
    monkeypatch.setattr(_auth.subprocess, "run", fake_run)

    ctx = make_ctx(headers={"Authorization": "Bearer abmcp_plain"})
    identity = _auth.check_api_key(ctx)

    assert identity is not None
    assert identity.paperless_username == "ada"
    assert identity.paperless_user_id == 42
    assert identity.is_admin is True
    assert identity.token_name == "Claude Desktop"
    assert _auth.get_mcp_identity(ctx) == identity
    assert calls == [
        {
            "command": ["php8.4", "artisan", "archibot:mcp-token-verify", "abmcp_plain"],
            "cwd": laravel_path,
            "check": False,
            "capture_output": True,
            "text": True,
            "timeout": 10,
        }
    ]


def test_laravel_mcp_token_verifier_rejects_revoked_or_unknown_token(monkeypatch):
    def fake_run(command, cwd, check, capture_output, text, timeout):
        return DummyCompletedProcess(1, '{"ok":false,"error":"invalid_token"}\n')

    monkeypatch.setattr(settings, "mcp_laravel_auth_enabled", True)
    monkeypatch.setattr(_auth.subprocess, "run", fake_run)

    with pytest.raises(ValueError, match="Invalid or revoked MCP token"):
        _auth.check_api_key(make_ctx(arguments={"_api_key": "abmcp_revoked"}))


def test_laravel_mcp_auth_requires_token(monkeypatch):
    monkeypatch.setattr(settings, "mcp_laravel_auth_enabled", True)

    with pytest.raises(ValueError, match="Invalid or missing MCP token"):
        _auth.check_api_key(make_ctx())


def test_laravel_write_permission_uses_verified_identity(monkeypatch):
    def fake_run(command, cwd, check, capture_output, text, timeout):
        return DummyCompletedProcess(
            0,
            '{"ok":true,"user":{"id":1,"paperless_username":"ada"},'
            '"permissions":{"mcp_write_enabled":true}}\n',
        )

    monkeypatch.setattr(settings, "mcp_laravel_auth_enabled", True)
    monkeypatch.setattr(_auth.subprocess, "run", fake_run)

    identity = _auth.require_mcp_write(make_ctx(headers={"x-api-key": "abmcp_write"}))

    assert identity is not None
    assert identity.mcp_write_enabled is True


def test_laravel_write_permission_rejects_read_only_token(monkeypatch):
    def fake_run(command, cwd, check, capture_output, text, timeout):
        return DummyCompletedProcess(
            0,
            '{"ok":true,"user":{"id":1,"paperless_username":"ada"},'
            '"permissions":{"mcp_write_enabled":false}}\n',
        )

    monkeypatch.setattr(settings, "mcp_laravel_auth_enabled", True)
    monkeypatch.setattr(_auth.subprocess, "run", fake_run)

    with pytest.raises(ValueError, match="MCP write tools are disabled for this token"):
        _auth.require_mcp_write(make_ctx(headers={"x-api-key": "abmcp_readonly"}))
