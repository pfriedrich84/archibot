"""Durable command helpers for event-driven recovery bridges."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from app.jobs.database import engine


@dataclass(frozen=True)
class CommandRecord:
    id: int
    type: str
    status: str
    payload: dict[str, Any]


def sql_text(statement: str):
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover - dependency is installed in target image
        raise RuntimeError("sqlalchemy is required for PostgreSQL-backed commands") from exc

    return text(statement)


def load_command(command_id: int) -> CommandRecord | None:
    """Load one durable command by id."""
    statement = sql_text(
        """
        SELECT id, type, status, payload
        FROM commands
        WHERE id = :command_id
        LIMIT 1
        """
    )
    with engine().connect() as connection:
        row = connection.execute(statement, {"command_id": command_id}).mappings().first()

    if row is None:
        return None

    payload = row["payload"] or {}
    if not isinstance(payload, dict):
        payload = {}

    return CommandRecord(
        id=int(row["id"]),
        type=str(row["type"]),
        status=str(row["status"]),
        payload=payload,
    )


def _list_pending_commands(command_type: str, limit: int) -> list[CommandRecord]:
    """Return pending commands of one durable command type."""
    statement = sql_text(
        """
        SELECT id, type, status, payload
        FROM commands
        WHERE type = :command_type
          AND status = 'pending'
        ORDER BY created_at ASC, id ASC
        LIMIT :limit
        """
    )
    with engine().connect() as connection:
        rows = (
            connection.execute(statement, {"command_type": command_type, "limit": limit})
            .mappings()
            .all()
        )

    records: list[CommandRecord] = []
    for row in rows:
        payload = row["payload"] or {}
        if not isinstance(payload, dict):
            payload = {}
        records.append(
            CommandRecord(
                id=int(row["id"]),
                type=str(row["type"]),
                status=str(row["status"]),
                payload=payload,
            )
        )

    return records


def list_pending_embedding_build_commands(limit: int = 100) -> list[CommandRecord]:
    """Return admin-requested embedding build commands ready for enqueue."""
    return _list_pending_commands("embedding_index_build", limit)


def list_pending_poll_reconciliation_commands(limit: int = 100) -> list[CommandRecord]:
    """Return admin-requested polling reconciliation commands ready for enqueue."""
    return _list_pending_commands("poll_reconciliation", limit)


def list_pending_reindex_commands(limit: int = 100) -> list[CommandRecord]:
    """Return admin-requested reindex commands ready for enqueue."""
    return _list_pending_commands("reindex", limit)


def list_pending_review_commit_commands(limit: int = 100) -> list[CommandRecord]:
    """Return accepted review commit commands ready for enqueue."""
    return _list_pending_commands("review_commit", limit)


def mark_command_status(command_id: int, status: str, error: str | None = None) -> None:
    """Persist command execution bridge status."""
    statement = sql_text(
        """
        UPDATE commands
        SET status = CAST(:status AS character varying),
            started_at = CASE WHEN CAST(:status_for_lifecycle AS character varying) IN ('queued', 'running') AND started_at IS NULL THEN CURRENT_TIMESTAMP ELSE started_at END,
            finished_at = CASE WHEN CAST(:status_for_lifecycle AS character varying) IN ('succeeded', 'failed', 'failed_permanent') THEN CURRENT_TIMESTAMP ELSE finished_at END,
            error = :error,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :command_id
        """
    )
    with engine().begin() as connection:
        connection.execute(
            statement,
            {
                "command_id": command_id,
                "status": status,
                "status_for_lifecycle": status,
                "error": error,
            },
        )
