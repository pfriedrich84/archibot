from __future__ import annotations

from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest

from app.config import settings
from app.mcp_tools import _auth, _deps, classify, documents
from app.models import ClassificationResult, PaperlessDocument


class DummyCompletedProcess:
    def __init__(self, returncode: int, stdout: str, stderr: str = "") -> None:
        self.returncode = returncode
        self.stdout = stdout
        self.stderr = stderr


def make_ctx(headers: dict[str, str] | None = None, arguments: dict[str, str] | None = None):
    meta = SimpleNamespace(headers=headers or {}, arguments=arguments or {})
    return SimpleNamespace(request_context=SimpleNamespace(meta=meta))


class CapturingMcp:
    def __init__(self) -> None:
        self.tools = {}

    def tool(self, name: str, **kwargs):
        def decorator(func):
            self.tools[name] = func
            return func

        return decorator


class NoGlobalPaperless:
    def __getattr__(self, name: str):
        raise AssertionError(f"global Paperless client must not be used for {name}")


class NoopRateLimiter:
    def check(self, key: str) -> None:
        return None


def make_laravel_ctx() -> SimpleNamespace:
    ctx = make_ctx(headers={"Authorization": "Bearer abmcp_scoped"})
    ctx.request_context.lifespan_context = SimpleNamespace(
        paperless=NoGlobalPaperless(),
        ollama=SimpleNamespace(),
        rate_limiter=NoopRateLimiter(),
    )
    return ctx


def enable_laravel_mcp_auth(monkeypatch, *, write_enabled: bool = False) -> None:
    monkeypatch.setattr(settings, "mcp_laravel_auth_enabled", True)

    def fake_verify(token: str):
        assert token == "abmcp_scoped"
        return {
            "ok": True,
            "user": {"id": 1, "paperless_user_id": 42, "paperless_username": "ada"},
            "token": {"id": 5, "name": "Claude Desktop"},
            "permissions": {"mcp_write_enabled": write_enabled},
            "paperless": {"url": "https://paperless.example", "token": "paperless-user-token"},
        }

    monkeypatch.setattr(_auth, "_verify_laravel_mcp_token", fake_verify)


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
            '"permissions":{"mcp_write_enabled":false},'
            '"paperless":{"url":"https://paperless.example","token":"paperless-user-token"}}\n',
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
    assert identity.paperless_url == "https://paperless.example"
    assert identity.paperless_token == "paperless-user-token"
    assert _auth.get_mcp_identity(ctx) == identity
    assert calls == [
        {
            "command": [
                "php8.4",
                "artisan",
                "archibot:mcp-token-verify",
                "abmcp_plain",
                "--include-paperless-context",
            ],
            "cwd": laravel_path,
            "check": False,
            "capture_output": True,
            "text": True,
            "timeout": 10,
        }
    ]


def test_laravel_mcp_token_verifier_rejects_revoked_or_unknown_token(monkeypatch):
    def fake_run(command, cwd, check, capture_output, text, timeout):
        assert "--include-paperless-context" in command
        return DummyCompletedProcess(1, '{"ok":false,"error":"invalid_token"}\n')

    monkeypatch.setattr(settings, "mcp_laravel_auth_enabled", True)
    monkeypatch.setattr(_auth.subprocess, "run", fake_run)

    with pytest.raises(ValueError, match="Invalid or revoked MCP token"):
        _auth.check_api_key(make_ctx(arguments={"_api_key": "abmcp_revoked"}))


def test_laravel_mcp_auth_requires_token(monkeypatch):
    monkeypatch.setattr(settings, "mcp_laravel_auth_enabled", True)

    with pytest.raises(ValueError, match="Invalid or missing MCP token"):
        _auth.check_api_key(make_ctx())


def test_get_paperless_returns_scoped_client_from_verified_identity():
    ctx = make_ctx()
    ctx.request_context.mcp_identity = _auth.McpIdentity(
        user_id=1,
        paperless_user_id=42,
        paperless_username="ada",
        is_admin=False,
        token_id=5,
        token_name="Claude Desktop",
        mcp_write_enabled=False,
        paperless_url="https://paperless.example",
        paperless_token="paperless-user-token",
    )

    paperless = _deps.get_paperless(ctx)

    assert paperless.base_url == "https://paperless.example"
    assert paperless.token == "paperless-user-token"


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


@pytest.mark.asyncio
async def test_mcp_get_document_uses_laravel_scoped_paperless_client(monkeypatch):
    enable_laravel_mcp_auth(monkeypatch)
    scoped_paperless = SimpleNamespace(
        get_document=AsyncMock(
            return_value=PaperlessDocument(id=123, title="Scoped", content="secret", tags=[9])
        )
    )

    def make_scoped_client(base_url: str | None = None, token: str | None = None):
        assert base_url == "https://paperless.example"
        assert token == "paperless-user-token"
        return scoped_paperless

    monkeypatch.setattr(_deps, "PaperlessClient", make_scoped_client)
    mcp = CapturingMcp()
    documents.register(mcp)

    result = await mcp.tools["get_document"](123, ctx=make_laravel_ctx())

    assert '"id": 123' in result
    scoped_paperless.get_document.assert_awaited_once_with(123)


@pytest.mark.asyncio
async def test_mcp_update_document_uses_laravel_scoped_paperless_client(monkeypatch):
    enable_laravel_mcp_auth(monkeypatch, write_enabled=True)
    monkeypatch.setattr(settings, "mcp_enable_write", True)
    scoped_paperless = SimpleNamespace(patch_document=AsyncMock())

    def make_scoped_client(base_url: str | None = None, token: str | None = None):
        assert base_url == "https://paperless.example"
        assert token == "paperless-user-token"
        return scoped_paperless

    class DummyConn:
        def __enter__(self):
            return self

        def __exit__(self, *args):
            return False

        def execute(self, *args, **kwargs):
            return None

    monkeypatch.setattr(_deps, "PaperlessClient", make_scoped_client)
    monkeypatch.setattr(documents, "get_conn", lambda: DummyConn())
    mcp = CapturingMcp()
    documents.register(mcp)

    result = await mcp.tools["update_document"](123, title="New title", ctx=make_laravel_ctx())

    assert '"ok": true' in result
    scoped_paperless.patch_document.assert_awaited_once_with(123, {"title": "New title"})


@pytest.mark.asyncio
async def test_mcp_classification_reads_and_lists_with_laravel_scoped_paperless_client(
    monkeypatch,
):
    enable_laravel_mcp_auth(monkeypatch)
    monkeypatch.setattr(settings, "paperless_inbox_tag_id", 9)
    doc = PaperlessDocument(id=123, title="Inbox", content="body", tags=[9])
    scoped_paperless = SimpleNamespace(
        get_document=AsyncMock(return_value=doc),
        list_correspondents=AsyncMock(return_value=[]),
        list_document_types=AsyncMock(return_value=[]),
        list_storage_paths=AsyncMock(return_value=[]),
        list_tags=AsyncMock(return_value=[]),
    )

    def make_scoped_client(base_url: str | None = None, token: str | None = None):
        assert base_url == "https://paperless.example"
        assert token == "paperless-user-token"
        return scoped_paperless

    async def fake_maybe_correct_ocr(document, ollama, paperless):
        assert paperless is scoped_paperless
        return document.content, 0

    async def fake_find_similar_documents(document, paperless, ollama):
        assert paperless is scoped_paperless
        return []

    async def fake_classify(
        document, context_docs, correspondents, doctypes, storage_paths, tags, ollama
    ):
        return (
            ClassificationResult(title="Classified", tags=[], confidence=88, reasoning="ok"),
            {"raw": True},
        )

    def fake_store_suggestion(*args, **kwargs):
        return SimpleNamespace(id=77)

    async def fake_index_document(document, ollama):
        return None

    monkeypatch.setattr(_deps, "PaperlessClient", make_scoped_client)
    monkeypatch.setattr("app.pipeline.ocr_correction.maybe_correct_ocr", fake_maybe_correct_ocr)
    monkeypatch.setattr(
        "app.pipeline.context_builder.find_similar_documents", fake_find_similar_documents
    )
    monkeypatch.setattr("app.pipeline.context_builder.index_document", fake_index_document)
    monkeypatch.setattr("app.pipeline.classifier.classify", fake_classify)
    monkeypatch.setattr("app.pipeline.document_processing.store_suggestion", fake_store_suggestion)
    mcp = CapturingMcp()
    classify.register(mcp)

    result = await mcp.tools["classify_document"](123, ctx=make_laravel_ctx())

    assert '"suggestion_id": 77' in result
    scoped_paperless.get_document.assert_awaited_once_with(123)
    scoped_paperless.list_correspondents.assert_awaited_once()
    scoped_paperless.list_document_types.assert_awaited_once()
    scoped_paperless.list_storage_paths.assert_awaited_once()
    scoped_paperless.list_tags.assert_awaited_once()
