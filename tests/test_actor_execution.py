from app.jobs import actor_execution


class FakeResult:
    def __init__(self, row=None):
        self.row = row

    def mappings(self):
        return self

    def first(self):
        return self.row


class FakeConnection:
    def __init__(self, calls, row=None, rows=None):
        self.calls = calls
        self.row = {"id": 123} if row is None else row
        self.rows = rows

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, traceback):
        return None

    def execute(self, statement, params):
        self.calls.append((statement, params))
        return FakeRows(self.rows) if self.rows is not None else FakeResult(self.row)


class FakeRows:
    def __init__(self, rows):
        self.rows = rows

    def mappings(self):
        return self

    def all(self):
        return self.rows


class FakeEngine:
    def __init__(self, calls, rows=None):
        self.calls = calls
        self.rows = rows

    def begin(self):
        return FakeConnection(self.calls)

    def connect(self):
        return FakeConnection(self.calls, rows=self.rows)


def test_start_actor_execution_inserts_running_row(monkeypatch):
    calls = []
    monkeypatch.setattr(actor_execution, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(actor_execution, "sql_text", lambda statement: statement)
    monkeypatch.setattr(actor_execution, "worker_id", lambda: "worker-test")

    handle = actor_execution.start_actor_execution(
        actor_name="handle_paperless_webhook",
        paperless_document_id=42,
        queue_name="archibot.webhook",
    )

    assert handle.id == 123
    assert handle.actor_name == "handle_paperless_webhook"
    assert calls[0][1]["paperless_document_id"] == 42
    assert calls[0][1]["queue_name"] == "archibot.webhook"
    assert calls[0][1]["worker_id"] == "worker-test"


def test_finish_actor_execution_updates_status(monkeypatch):
    calls = []
    monkeypatch.setattr(actor_execution, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(actor_execution, "sql_text", lambda statement: statement)
    monkeypatch.setattr(actor_execution.time, "monotonic", lambda: 12.5)

    handle = actor_execution.ActorExecutionHandle(
        id=123,
        actor_name="handle_paperless_webhook",
        started_monotonic=10.0,
    )
    actor_execution.finish_actor_execution(
        handle,
        status="failed",
        error_type="RuntimeError",
        error_message="boom",
    )

    assert calls[0][1] == {
        "actor_execution_id": 123,
        "status": "failed",
        "duration_ms": 2500,
        "error_type": "RuntimeError",
        "error_message": "boom",
    }


def test_list_stale_running_actor_executions_returns_records(monkeypatch):
    calls = []
    rows = [
        {
            "id": 77,
            "pipeline_run_id": 88,
            "paperless_document_id": 42,
            "actor_name": "handle_document_pipeline",
            "attempt": 2,
            "max_attempts": 5,
        }
    ]
    monkeypatch.setattr(actor_execution, "engine", lambda: FakeEngine(calls, rows=rows))
    monkeypatch.setattr(actor_execution, "sql_text", lambda statement: statement)

    records = actor_execution.list_stale_running_actor_executions(
        stale_after_seconds=123,
        limit=5,
    )

    assert records == [
        actor_execution.StaleActorExecutionRecord(
            id=77,
            pipeline_run_id=88,
            paperless_document_id=42,
            actor_name="handle_document_pipeline",
            attempt=2,
            max_attempts=5,
        )
    ]
    assert calls[0][1] == {"stale_after_seconds": 123, "limit": 5}


def test_mark_stale_actor_execution_recovered_updates_retry_state(monkeypatch):
    calls = []
    monkeypatch.setattr(actor_execution, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(actor_execution, "sql_text", lambda statement: statement)

    actor_execution.mark_stale_actor_execution_recovered(77)

    assert calls[0][1] == {
        "actor_execution_id": 77,
        "status": "retrying",
        "error_type": "worker_recovery_stale_actor",
        "error_message": "Actor execution was left running and recovered after worker restart.",
    }
