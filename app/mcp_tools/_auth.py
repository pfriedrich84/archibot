"""Authentication and rate limiting for MCP tools."""

from __future__ import annotations

import json
import subprocess
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

import structlog
from mcp.server.fastmcp import Context

from app.config import settings

log = structlog.get_logger(__name__)


@dataclass(frozen=True)
class McpIdentity:
    """Verified MCP caller identity returned by Laravel."""

    user_id: int | None
    paperless_user_id: int | None
    paperless_username: str | None
    is_admin: bool
    token_id: int | None
    token_name: str | None
    mcp_write_enabled: bool
    paperless_url: str | None = None
    paperless_token: str | None = field(default=None, repr=False)

    @classmethod
    def from_laravel_payload(cls, payload: dict[str, Any]) -> McpIdentity:
        user = payload.get("user") or {}
        token = payload.get("token") or {}
        permissions = payload.get("permissions") or {}
        paperless = payload.get("paperless") or {}
        return cls(
            user_id=user.get("id"),
            paperless_user_id=user.get("paperless_user_id"),
            paperless_username=user.get("paperless_username"),
            is_admin=bool(user.get("is_admin")),
            token_id=token.get("id"),
            token_name=token.get("name"),
            mcp_write_enabled=bool(permissions.get("mcp_write_enabled")),
            paperless_url=paperless.get("url"),
            paperless_token=paperless.get("token"),
        )


class RateLimiter:
    """Simple sliding-window rate limiter (per-hour)."""

    def __init__(self, max_per_hour: int) -> None:
        self.max_per_hour = max_per_hour
        self._timestamps: dict[str, list[float]] = {}

    def check(self, key: str) -> None:
        """Raise ValueError if the rate limit for *key* is exceeded."""
        if self.max_per_hour <= 0:
            return  # unlimited

        now = time.monotonic()
        window = 3600.0  # 1 hour
        stamps = self._timestamps.setdefault(key, [])

        # Prune old entries
        stamps[:] = [t for t in stamps if now - t < window]

        if len(stamps) >= self.max_per_hour:
            log.warning("rate limit exceeded", key=key, limit=self.max_per_hour)
            raise ValueError(
                f"Rate limit exceeded: max {self.max_per_hour} calls per hour for '{key}'. "
                f"Try again later."
            )
        stamps.append(now)


def _extract_token(ctx: Context) -> str | None:
    """Extract an MCP bearer token from HTTP headers or tool metadata.

    FastMCP exposes transport metadata slightly differently across transports,
    so this helper accepts the shapes used by HTTP headers and by explicit
    ``_api_key`` / ``api_key`` tool metadata.
    """
    meta = getattr(getattr(ctx, "request_context", None), "meta", None)
    headers = getattr(meta, "headers", None) if meta else None
    if headers:
        provided = headers.get("x-api-key") or headers.get("X-API-Key")
        authorization = headers.get("authorization") or headers.get("Authorization")
        if authorization and authorization.lower().startswith("bearer "):
            provided = authorization[7:].strip()
        if provided:
            return provided

    for source in (
        meta,
        getattr(getattr(ctx, "request_context", None), "request", None),
        getattr(getattr(ctx, "request_context", None), "params", None),
    ):
        token = _find_token(source)
        if token:
            return token

    return None


def _find_token(value: Any) -> str | None:
    if value is None:
        return None
    if isinstance(value, dict):
        for key in ("_api_key", "api_key", "token"):
            token = value.get(key)
            if isinstance(token, str) and token:
                return token
        for nested_key in ("arguments", "params", "metadata"):
            token = _find_token(value.get(nested_key))
            if token:
                return token
        return None
    for attr in ("_api_key", "api_key", "token", "arguments", "params", "metadata"):
        token = _find_token(getattr(value, attr, None))
        if token:
            return token
    return None


def _verify_laravel_mcp_token(token: str) -> dict[str, Any]:
    laravel_path = Path(settings.mcp_laravel_path)
    if not laravel_path.is_absolute():
        laravel_path = Path.cwd() / laravel_path

    command = [
        settings.mcp_laravel_php_binary,
        "artisan",
        "archibot:mcp-token-verify",
        token,
        "--include-paperless-context",
    ]
    try:
        result = subprocess.run(
            command,
            cwd=laravel_path,
            check=False,
            capture_output=True,
            text=True,
            timeout=10,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        log.warning("laravel mcp token verifier unavailable", error=str(exc))
        raise ValueError("MCP token verifier unavailable.") from exc

    output = (result.stdout or "").strip().splitlines()[-1:] or [""]
    try:
        payload = json.loads(output[0])
    except json.JSONDecodeError as exc:
        log.warning("laravel mcp token verifier returned invalid json", stderr=result.stderr)
        raise ValueError("MCP token verifier returned an invalid response.") from exc

    if result.returncode != 0 or not payload.get("ok"):
        log.warning("laravel mcp token rejected", error=payload.get("error"))
        raise ValueError("Invalid or revoked MCP token.")

    return payload


def _set_mcp_identity(ctx: Context, identity: McpIdentity | None) -> None:
    request_context = getattr(ctx, "request_context", None)
    if request_context is not None:
        request_context.mcp_identity = identity


def get_mcp_identity(ctx: Context) -> McpIdentity | None:
    """Return the verified Laravel MCP identity for this request, if any."""
    return getattr(getattr(ctx, "request_context", None), "mcp_identity", None)


def check_api_key(ctx: Context) -> McpIdentity | None:
    """Validate MCP credentials for a tool call.

    Enable ``MCP_LARAVEL_AUTH_ENABLED`` so every tool call is checked against
    Laravel-managed, per-user MCP tokens via
    ``php artisan archibot:mcp-token-verify``. The legacy static ``MCP_API_KEY``
    path remains available for local stdio development and non-Laravel MCP
    deployments; when neither auth mechanism is configured this is a no-op for
    backward compatibility.
    """
    provided = _extract_token(ctx)

    if settings.mcp_laravel_auth_enabled:
        if not provided:
            raise ValueError("Invalid or missing MCP token.")
        identity = McpIdentity.from_laravel_payload(_verify_laravel_mcp_token(provided))
        _set_mcp_identity(ctx, identity)
        return identity

    expected = settings.mcp_api_key
    if not expected:
        _set_mcp_identity(ctx, None)
        return None  # no auth configured

    if provided != expected:
        log.warning("api key mismatch")
        raise ValueError("Invalid or missing API key.")

    _set_mcp_identity(ctx, None)
    return None


def require_mcp_write(ctx: Context) -> McpIdentity | None:
    """Validate auth and require write permission for a mutating MCP tool."""
    identity = check_api_key(ctx)
    if settings.mcp_laravel_auth_enabled:
        if not identity or not identity.mcp_write_enabled:
            raise ValueError("MCP write tools are disabled for this token.")
        return identity
    if not settings.mcp_enable_write:
        raise ValueError("MCP write tools are disabled.")
    return identity
