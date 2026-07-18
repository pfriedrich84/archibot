"""PostgreSQL repository for durable entity approval/blacklist state."""

from __future__ import annotations

from app.jobs.database import engine


def _text(statement: str):
    try:
        from sqlalchemy import text
    except ModuleNotFoundError as exc:  # pragma: no cover
        raise RuntimeError("sqlalchemy is required for PostgreSQL entity approvals") from exc
    return text(statement)


def rejected_entity_names(entity_type: str, *, limit: int = 100) -> list[str]:
    """Return rejected names from Laravel's shared entity approval table."""
    if entity_type not in {"tag", "correspondent", "document_type"}:
        return []
    statement = _text(
        """
        SELECT name
        FROM entity_approvals
        WHERE type = :entity_type AND status = 'rejected'
        ORDER BY LOWER(name), id
        LIMIT :limit
        """
    )
    with engine().connect() as connection:
        rows = (
            connection.execute(
                statement, {"entity_type": entity_type, "limit": max(1, min(limit, 1000))}
            )
            .mappings()
            .all()
        )
    return [str(row["name"]).strip() for row in rows if str(row["name"]).strip()]
