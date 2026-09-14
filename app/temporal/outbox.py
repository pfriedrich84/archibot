"""PostgreSQL-backed delivery state for Temporal client intents."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from app.jobs.commands import sql_text
from app.jobs.database import engine


@dataclass(frozen=True)
class OutboxIntent:
    """One claimed, immutable Temporal client operation."""

    id: int
    intent_key: str
    operation: str
    workflow_id: str
    workflow_type: str | None
    task_queue: str | None
    signal_name: str | None
    payload: dict[str, Any]
    attempts: int


def claim_next_intent(lease_seconds: int) -> OutboxIntent | None:
    """Claim one due intent, recovering a relay lease abandoned by a crash."""
    statement = sql_text(
        """
        WITH candidate AS (
            SELECT id
            FROM temporal_outbox_intents
            WHERE (
                    status = 'pending'
                    AND available_at <= CURRENT_TIMESTAMP
                  )
               OR (
                    status = 'delivering'
                    AND locked_at <= CURRENT_TIMESTAMP - make_interval(secs => :lease_seconds)
                  )
            ORDER BY id ASC
            FOR UPDATE SKIP LOCKED
            LIMIT 1
        )
        UPDATE temporal_outbox_intents AS intent
        SET status = 'delivering',
            attempts = intent.attempts + 1,
            locked_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        FROM candidate
        WHERE intent.id = candidate.id
        RETURNING intent.id,
                  intent.intent_key,
                  intent.operation,
                  intent.workflow_id,
                  intent.workflow_type,
                  intent.task_queue,
                  intent.signal_name,
                  intent.payload,
                  intent.attempts
        """
    )
    with engine().begin() as connection:
        row = connection.execute(statement, {"lease_seconds": lease_seconds}).mappings().first()

    if row is None:
        return None

    payload = row["payload"] if isinstance(row["payload"], dict) else {}
    return OutboxIntent(
        id=int(row["id"]),
        intent_key=str(row["intent_key"]),
        operation=str(row["operation"]),
        workflow_id=str(row["workflow_id"]),
        workflow_type=None if row["workflow_type"] is None else str(row["workflow_type"]),
        task_queue=None if row["task_queue"] is None else str(row["task_queue"]),
        signal_name=None if row["signal_name"] is None else str(row["signal_name"]),
        payload=payload,
        attempts=int(row["attempts"]),
    )


def mark_delivered(intent_id: int) -> None:
    """Acknowledge a Temporal operation after the server accepted it."""
    statement = sql_text(
        """
        UPDATE temporal_outbox_intents
        SET status = 'delivered',
            delivered_at = CURRENT_TIMESTAMP,
            locked_at = NULL,
            last_error = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :intent_id
          AND status = 'delivering'
        """
    )
    with engine().begin() as connection:
        connection.execute(statement, {"intent_id": intent_id})


def mark_failed(intent: OutboxIntent, error: str, max_attempts: int) -> None:
    """Delay transient delivery failures and dead-letter exhausted intents."""
    exhausted = intent.attempts >= max_attempts
    delay_seconds = min(60, 2 ** min(intent.attempts, 6))
    statement = sql_text(
        """
        UPDATE temporal_outbox_intents
        SET status = CAST(:status AS character varying),
            available_at = CURRENT_TIMESTAMP + make_interval(secs => :delay_seconds),
            locked_at = NULL,
            last_error = :error,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :intent_id
          AND status = 'delivering'
        """
    )
    with engine().begin() as connection:
        updated = connection.execute(
            statement,
            {
                "intent_id": intent.id,
                "status": "dead_letter" if exhausted else "pending",
                "delay_seconds": delay_seconds,
                "error": error[:1000],
            },
        )
        command_id = intent.payload.get("command_id")
        if (
            exhausted
            and updated.rowcount == 1
            and intent.operation in {"start_workflow", "signal_workflow"}
            and isinstance(command_id, int)
            and not isinstance(command_id, bool)
        ):
            connection.execute(
                sql_text(
                    """
                    UPDATE commands
                    SET status = 'failed_permanent', finished_at = CURRENT_TIMESTAMP,
                        error = 'Temporal workflow intent delivery exhausted its retries.',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :command_id
                      AND status IN ('pending', 'queued')
                      AND payload->>'orchestration_driver' = 'temporal'
                      AND payload->>'temporal_workflow_id' = :workflow_id
                    """
                ),
                {"command_id": command_id, "workflow_id": intent.workflow_id},
            )
        review_suggestion_id = intent.payload.get("review_suggestion_id")
        if (
            exhausted
            and updated.rowcount == 1
            and intent.operation in {"start_workflow", "signal_workflow"}
            and isinstance(review_suggestion_id, int)
            and not isinstance(review_suggestion_id, bool)
            and isinstance(command_id, int)
            and not isinstance(command_id, bool)
        ):
            connection.execute(
                sql_text(
                    """
                    UPDATE review_suggestions
                    SET commit_status = 'failed', updated_at = CURRENT_TIMESTAMP
                    WHERE id = :review_suggestion_id
                      AND commit_command_id = :command_id
                      AND commit_status <> 'committed'
                    """
                ),
                {
                    "review_suggestion_id": review_suggestion_id,
                    "command_id": command_id,
                },
            )
        pipeline_run_id = intent.payload.get("pipeline_run_id")
        if (
            exhausted
            and updated.rowcount == 1
            and intent.operation == "start_workflow"
            and isinstance(pipeline_run_id, int)
            and not isinstance(pipeline_run_id, bool)
        ):
            connection.execute(
                sql_text(
                    """
                    UPDATE pipeline_runs
                    SET status = 'failed_permanent', finished_at = CURRENT_TIMESTAMP,
                        error_type = 'temporal_start_delivery_exhausted',
                        error = 'Temporal workflow start delivery exhausted its retries.',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :pipeline_run_id
                      AND status IN ('pending', 'queued', 'blocked')
                      AND orchestration_driver = 'temporal'
                      AND temporal_workflow_id = :workflow_id
                    """
                ),
                {"pipeline_run_id": pipeline_run_id, "workflow_id": intent.workflow_id},
            )
