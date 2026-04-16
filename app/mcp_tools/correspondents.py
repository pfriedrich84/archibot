"""Correspondent whitelist and blacklist tools — listing, approval, and blacklist management."""

from __future__ import annotations

import json

import structlog
from mcp.server.fastmcp import Context, FastMCP
from mcp.types import ToolAnnotations

from app.config import settings
from app.db import get_conn
from app.mcp_tools._auth import check_api_key
from app.mcp_tools._deps import get_deps
from app.pipeline.committer import retroactive_correspondent_apply

log = structlog.get_logger(__name__)

_RO = ToolAnnotations(readOnlyHint=True, destructiveHint=False)


def register(mcp: FastMCP) -> None:
    # ------------------------------------------------------------------
    # Always registered (read-only)
    # ------------------------------------------------------------------
    @mcp.tool(
        name="list_correspondent_proposals",
        description=(
            "List correspondents proposed by the AI that are not yet approved. "
            "These are new correspondents the LLM suggested but that don't exist in Paperless yet."
        ),
        annotations=_RO,
    )
    async def list_correspondent_proposals(ctx: Context = None) -> str:
        check_api_key(ctx)
        with get_conn() as conn:
            rows = conn.execute(
                "SELECT name, times_seen, first_seen, notes "
                "FROM correspondent_whitelist WHERE approved = 0 "
                "ORDER BY times_seen DESC"
            ).fetchall()

        items = [
            {
                "name": r["name"],
                "times_seen": r["times_seen"],
                "first_seen": r["first_seen"],
                "notes": r["notes"],
            }
            for r in rows
        ]
        return json.dumps(items, ensure_ascii=False)

    @mcp.tool(
        name="list_blacklisted_correspondents",
        description=(
            "List correspondents that have been blacklisted (rejected). "
            "Blacklisted correspondents are silently skipped when the classifier proposes them."
        ),
        annotations=_RO,
    )
    async def list_blacklisted_correspondents(ctx: Context = None) -> str:
        check_api_key(ctx)
        with get_conn() as conn:
            rows = conn.execute(
                "SELECT name, times_seen, rejected_at, notes "
                "FROM correspondent_blacklist ORDER BY rejected_at DESC"
            ).fetchall()

        items = [
            {
                "name": r["name"],
                "times_seen": r["times_seen"],
                "rejected_at": r["rejected_at"],
                "notes": r["notes"],
            }
            for r in rows
        ]
        return json.dumps(items, ensure_ascii=False)

    # ------------------------------------------------------------------
    # Write tools — only registered when MCP_ENABLE_WRITE=true
    # ------------------------------------------------------------------
    if settings.mcp_enable_write:

        @mcp.tool(
            name="approve_correspondent",
            description=(
                "Approve a proposed correspondent: creates it in Paperless-NGX, marks it "
                "as approved in the whitelist, and retroactively applies it to "
                "already-committed documents that had proposed this correspondent."
            ),
            annotations=ToolAnnotations(
                readOnlyHint=False, destructiveHint=False, idempotentHint=False
            ),
        )
        async def approve_correspondent(name: str, ctx: Context = None) -> str:
            check_api_key(ctx)
            deps = get_deps(ctx)

            with get_conn() as conn:
                row = conn.execute(
                    "SELECT name, approved, paperless_id FROM correspondent_whitelist WHERE name = ?",
                    (name,),
                ).fetchone()

            if not row:
                return json.dumps({"error": f"Correspondent '{name}' not found in proposals."})
            if row["approved"]:
                return json.dumps(
                    {
                        "error": (
                            f"Correspondent '{name}' is already approved "
                            f"(Paperless ID: {row['paperless_id']})."
                        )
                    }
                )

            entity = await deps.paperless.create_correspondent(name)

            with get_conn() as conn:
                conn.execute(
                    "UPDATE correspondent_whitelist SET approved = 1, paperless_id = ? WHERE name = ?",
                    (entity.id, name),
                )
                conn.execute(
                    """
                    INSERT INTO audit_log (action, document_id, actor, details)
                    VALUES ('mcp_approve_correspondent', NULL, 'mcp', ?)
                    """,
                    (json.dumps({"correspondent_name": name, "paperless_id": entity.id}),),
                )

            log.info(
                "correspondent approved via MCP", correspondent_name=name, paperless_id=entity.id
            )

            patched, pending = await retroactive_correspondent_apply(
                name, entity.id, deps.paperless
            )

            return json.dumps(
                {
                    "ok": True,
                    "correspondent_name": name,
                    "paperless_id": entity.id,
                    "patched_docs": patched,
                    "updated_pending": pending,
                }
            )

        @mcp.tool(
            name="unblacklist_correspondent",
            description=(
                "Remove a correspondent from the blacklist. The classifier will be able to "
                "propose this correspondent again in future classifications."
            ),
            annotations=ToolAnnotations(
                readOnlyHint=False, destructiveHint=False, idempotentHint=True
            ),
        )
        async def unblacklist_correspondent(name: str, ctx: Context = None) -> str:
            check_api_key(ctx)
            with get_conn() as conn:
                row = conn.execute(
                    "SELECT 1 FROM correspondent_blacklist WHERE name = ?", (name,)
                ).fetchone()
                if not row:
                    return json.dumps({"error": f"Correspondent '{name}' is not blacklisted."})
                conn.execute("DELETE FROM correspondent_blacklist WHERE name = ?", (name,))
                conn.execute(
                    """
                    INSERT INTO audit_log (action, document_id, actor, details)
                    VALUES ('mcp_unblacklist_correspondent', NULL, 'mcp', ?)
                    """,
                    (json.dumps({"correspondent_name": name}),),
                )

            log.info("correspondent unblacklisted via MCP", correspondent_name=name)
            return json.dumps({"ok": True, "correspondent_name": name})
