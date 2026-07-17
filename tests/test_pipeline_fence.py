import pytest

from app.jobs import pipeline_fence


class FakeCursor:
    def __init__(self, connection):
        self.connection = connection

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return None

    def execute(self, statement, parameters=None):
        normalized = " ".join(statement.split())
        self.connection.calls.append(("execute", normalized, parameters))
        if (
            "pg_advisory_lock" in normalized
            and "unlock" not in normalized
            and self.connection.acquire_error is not None
        ):
            raise self.connection.acquire_error
        if "pg_advisory_unlock" in normalized and self.connection.unlock_error is not None:
            raise self.connection.unlock_error

    def fetchone(self):
        if self.connection.ready_result is not None:
            result = self.connection.ready_result
            self.connection.ready_result = None
            return result
        return (True,)


class FakeConnection:
    def __init__(
        self,
        *,
        ready_result=None,
        acquire_error=None,
        unlock_error=None,
        close_error=None,
    ):
        self.calls = []
        self.ready_result = ready_result
        self.acquire_error = acquire_error
        self.unlock_error = unlock_error
        self.close_error = close_error

    def cursor(self):
        return FakeCursor(self)

    def close(self):
        self.calls.append(("close",))
        if self.close_error is not None:
            raise self.close_error


@pytest.mark.parametrize(
    ("factory", "lock_name", "unlock_name"),
    [
        (
            pipeline_fence.document_actor_lease,
            "pg_advisory_lock_shared",
            "pg_advisory_unlock_shared",
        ),
        (pipeline_fence.embedding_mutation_lease, "pg_advisory_lock", "pg_advisory_unlock"),
    ],
)
def test_python_child_owns_dedicated_session_for_complete_lease(
    monkeypatch, factory, lock_name, unlock_name
):
    connection = FakeConnection()
    connect_calls = []
    monkeypatch.setattr(
        pipeline_fence.psycopg,
        "connect",
        lambda url, autocommit: connect_calls.append((url, autocommit)) or connection,
    )

    with factory() as owned_connection:
        assert owned_connection is connection
        assert lock_name in connection.calls[0][1]
        assert not any(call[0] == "close" for call in connection.calls)

    assert unlock_name in connection.calls[-2][1]
    assert connection.calls[-1] == ("close",)
    assert connect_calls[0][1] is True


def test_readiness_is_revalidated_on_lease_owning_session():
    connection = FakeConnection(ready_result=("complete",))

    assert pipeline_fence.embedding_index_ready(connection) is True
    assert "FROM embedding_index_state" in connection.calls[0][1]


def test_parent_protocol_does_not_share_or_transfer_a_lease_to_child(monkeypatch):
    """Model parent death: only the child-owned connection can be closed/unlocked.

    Laravel launches the process without an advisory lease.  Therefore parent
    death has no lease object to release; this test proves the Python protocol
    creates, retains and releases its own independent session around mutation.
    """
    child_connection = FakeConnection()
    parent_connection = FakeConnection()
    opened = []
    monkeypatch.setattr(
        pipeline_fence.psycopg,
        "connect",
        lambda *args, **kwargs: opened.append(child_connection) or child_connection,
    )

    # Simulated parent death closes only an unrelated parent DB session.
    parent_connection.close()
    with pipeline_fence.document_actor_lease():
        assert opened == [child_connection]
        assert child_connection.calls[0][0] == "execute"
        assert child_connection.calls[-1][0] != "close"

    assert parent_connection.calls == [("close",)]
    assert child_connection.calls[-1] == ("close",)


def test_acquisition_failure_remains_primary_when_close_also_fails(monkeypatch):
    acquisition = RuntimeError("acquisition failed")
    connection = FakeConnection(
        acquire_error=acquisition,
        close_error=RuntimeError("close failed"),
    )
    monkeypatch.setattr(pipeline_fence.psycopg, "connect", lambda *a, **k: connection)

    with (
        pytest.raises(RuntimeError, match="acquisition failed") as caught,
        pipeline_fence.document_actor_lease(),
    ):
        pytest.fail("callback must not run")

    assert caught.value is acquisition
    assert not any("unlock" in call[1] for call in connection.calls if call[0] == "execute")
    assert any("connection-close" in note for note in caught.value.__notes__)


def test_callback_failure_remains_primary_when_unlock_fails(monkeypatch):
    callback = ValueError("callback failed")
    connection = FakeConnection(unlock_error=RuntimeError("unlock failed"))
    monkeypatch.setattr(pipeline_fence.psycopg, "connect", lambda *a, **k: connection)

    with (
        pytest.raises(ValueError, match="callback failed") as caught,
        pipeline_fence.document_actor_lease(),
    ):
        raise callback

    assert caught.value is callback
    assert any("unlock" in note for note in caught.value.__notes__)
    assert connection.calls[-1] == ("close",)


def test_close_failure_is_propagated_after_successful_unlock(monkeypatch):
    close = RuntimeError("close failed")
    connection = FakeConnection(close_error=close)
    monkeypatch.setattr(pipeline_fence.psycopg, "connect", lambda *a, **k: connection)

    with (
        pytest.raises(RuntimeError, match="close failed") as caught,
        pipeline_fence.document_actor_lease(),
    ):
        pass

    assert caught.value is close
    assert any("pg_advisory_unlock_shared" in call[1] for call in connection.calls)
