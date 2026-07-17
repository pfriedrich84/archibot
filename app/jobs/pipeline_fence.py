"""PostgreSQL advisory leases shared by productive Python pipeline actors.

The lease connection belongs to the Python child process.  It is intentionally
independent of Laravel's database session so killing the queue-worker parent
cannot release a live child's lease.  Document actors use a shared lease;
embedding build/reindex actors use an exclusive lease.
"""

from __future__ import annotations

from collections.abc import Iterator
from contextlib import contextmanager
from typing import Any

import psycopg

from app.config import settings

PIPELINE_FENCE_KEY = 4_701_142_607_001


def _psycopg_database_url() -> str:
    url = settings.database_url
    if url.startswith("postgresql+psycopg://"):
        return "postgresql://" + url[len("postgresql+psycopg://") :]
    return url


def _add_cleanup_note(primary: BaseException, action: str, error: BaseException) -> None:
    """Attach a secondary cleanup failure without replacing the primary error."""
    primary.add_note(f"Pipeline lease {action} cleanup also failed: {error!r}")


@contextmanager
def _lease(*, shared: bool) -> Iterator[Any]:
    """Own one session advisory lease for the complete callback lifetime.

    Acquisition or callback failures are always primary. Cleanup is attempted
    without masking them, and unlock is attempted only after acquisition.
    With no primary failure, the first cleanup failure is propagated.
    """
    connection = psycopg.connect(_psycopg_database_url(), autocommit=True)
    lock = "pg_advisory_lock_shared" if shared else "pg_advisory_lock"
    unlock = "pg_advisory_unlock_shared" if shared else "pg_advisory_unlock"
    acquired = False
    primary: BaseException | None = None
    cleanup_failure: BaseException | None = None
    try:
        with connection.cursor() as cursor:
            cursor.execute(f"SELECT {lock}(%s)", (PIPELINE_FENCE_KEY,))
            # The server owns the session lock once execute returns. Mark it
            # before cursor teardown, which can itself fail.
            acquired = True
        yield connection
    except BaseException as error:
        primary = error
        raise
    finally:
        if acquired:
            try:
                with connection.cursor() as cursor:
                    cursor.execute(f"SELECT {unlock}(%s)", (PIPELINE_FENCE_KEY,))
                    released = cursor.fetchone()
                    if released is not None and released[0] is not True:
                        raise RuntimeError("Python pipeline advisory lease was not owned at unlock")
            except BaseException as error:
                if primary is not None:
                    _add_cleanup_note(primary, "unlock", error)
                else:
                    cleanup_failure = error
        try:
            connection.close()
        except BaseException as error:
            if primary is not None:
                _add_cleanup_note(primary, "connection-close", error)
            elif cleanup_failure is not None:
                _add_cleanup_note(cleanup_failure, "connection-close", error)
            else:
                cleanup_failure = error
        if primary is None and cleanup_failure is not None:
            raise cleanup_failure


@contextmanager
def document_actor_lease() -> Iterator[Any]:
    """Hold the shared lease across all document actor reads and mutations."""
    with _lease(shared=True) as connection:
        yield connection


@contextmanager
def embedding_mutation_lease() -> Iterator[Any]:
    """Hold the exclusive lease across the full stale/build/reindex lifecycle."""
    with _lease(shared=False) as connection:
        yield connection


def embedding_index_ready(connection: Any) -> bool:
    """Revalidate readiness on the lease-owning PostgreSQL session."""
    with connection.cursor() as cursor:
        cursor.execute(
            """
            SELECT status
            FROM embedding_index_state
            ORDER BY created_at DESC, id DESC
            LIMIT 1
            """
        )
        row = cursor.fetchone()
    return row is not None and row[0] == "complete"
