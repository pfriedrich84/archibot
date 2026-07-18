"""Fail-closed MCP authentication and registration-disposition tests."""

from __future__ import annotations

from types import SimpleNamespace

import pytest

from app.config import settings
from app.mcp_tools import (
    _auth,
    classify,
    correspondents,
    doctypes,
    documents,
    entities,
    resources,
    suggestions,
    system,
    tags,
)


class CapturingMcp:
    def __init__(self) -> None:
        self.tools: dict[str, object] = {}
        self.resources: dict[str, object] = {}

    def tool(self, name: str, **kwargs):
        del kwargs

        def decorator(func):
            self.tools[name] = func
            return func

        return decorator

    def resource(self, uri: str, **kwargs):
        del kwargs

        def decorator(func):
            self.resources[uri] = func
            return func

        return decorator


def make_ctx(token: str = "abmcp_scoped") -> SimpleNamespace:
    meta = SimpleNamespace(headers={"Authorization": f"Bearer {token}"})
    return SimpleNamespace(request_context=SimpleNamespace(meta=meta))


@pytest.fixture(autouse=True)
def verified_auth(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(settings, "mcp_laravel_auth_enabled", True)
    monkeypatch.setattr(settings, "mcp_api_key", "")

    def verify(token: str):
        if token != "abmcp_scoped":
            raise ValueError("Invalid or revoked MCP token.")
        return {
            "ok": True,
            "user": {"id": 1, "paperless_user_id": 42, "paperless_username": "ada"},
            "token": {"id": 5, "name": "Desktop"},
            "permissions": {"mcp_write_enabled": False},
            "paperless": {"url": "https://paperless.example", "token": "user-token"},
        }

    monkeypatch.setattr(_auth, "_verify_laravel_mcp_token", verify)


def test_verified_identity_requires_laravel_and_complete_paperless_context() -> None:
    identity = _auth.require_verified_identity(make_ctx())

    assert identity.user_id == 1
    assert identity.paperless_user_id == 42
    assert identity.paperless_url == "https://paperless.example"


def test_verified_identity_rejects_revoked_token() -> None:
    with pytest.raises(ValueError, match="Invalid or revoked MCP token"):
        _auth.require_verified_identity(make_ctx("abmcp_revoked"))


def test_verified_identity_rejects_disabled_laravel_auth(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(settings, "mcp_laravel_auth_enabled", False)

    with pytest.raises(ValueError, match="Verified Laravel MCP identity is required"):
        _auth.require_verified_identity(make_ctx())


def test_verified_identity_rejects_incomplete_paperless_context(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(
        _auth,
        "_verify_laravel_mcp_token",
        lambda token: {
            "ok": True,
            "user": {"id": 1},
            "token": {"id": 5},
            "permissions": {"mcp_write_enabled": False},
            "paperless": {"url": None, "token": None},
        },
    )

    with pytest.raises(ValueError, match="Verified Laravel MCP identity is incomplete"):
        _auth.require_verified_identity(make_ctx())


def test_read_only_identity_cannot_use_write_guard() -> None:
    with pytest.raises(ValueError, match="MCP write tools are disabled for this token"):
        _auth.require_mcp_write(make_ctx())


def test_every_baseline_module_is_retired_until_laravel_postgres_seams_exist() -> None:
    mcp = CapturingMcp()
    modules = [
        classify,
        correspondents,
        doctypes,
        documents,
        entities,
        resources,
        suggestions,
        system,
        tags,
    ]

    for module in modules:
        module.register(mcp)

    assert mcp.tools == {}
    assert mcp.resources == {}
