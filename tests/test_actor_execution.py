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
        self.row = {"id": 123, "attempt": 2} if row is None else row
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
    assert handle.attempt == 2
    assert calls[0][1]["paperless_document_id"] == 42
    assert calls[0][1]["command_id"] is None
    assert calls[0][1]["webhook_delivery_id"] is None
    assert "WITH next_attempt" in calls[0][0]
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
        "execution_token": None,
        "source_version": None,
    }


def test_schedule_actor_execution_retry_updates_retry_metadata(monkeypatch):
    calls = []
    monkeypatch.setattr(actor_execution, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(actor_execution, "sql_text", lambda statement: statement)
    monkeypatch.setattr(actor_execution.time, "monotonic", lambda: 12.5)

    handle = actor_execution.ActorExecutionHandle(
        id=123,
        actor_name="commit_review_suggestion",
        started_monotonic=10.0,
    )
    actor_execution.schedule_actor_execution_retry(
        handle,
        retry_class="transient_paperless",
        retry_reason="TimeoutError",
        backoff_seconds=30,
        error_message="slow",
    )

    assert calls[0][1] == {
        "actor_execution_id": 123,
        "duration_ms": 2500,
        "retry_class": "transient_paperless",
        "retry_reason": "TimeoutError",
        "backoff_seconds": 30,
        "error_message": "slow",
        "execution_token": None,
        "source_version": None,
    }


def test_list_stale_running_actor_executions_returns_records(monkeypatch):
    calls = []
    rows = [
        {
            "id": 77,
            "pipeline_run_id": 88,
            "command_id": None,
            "webhook_delivery_id": None,
            "paperless_document_id": 42,
            "actor_name": "handle_document_pipeline",
            "attempt": 2,
            "max_attempts": 5,
            "execution_token": "stale-token",
            "source_version": 7,
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
            command_id=None,
            webhook_delivery_id=None,
            paperless_document_id=42,
            actor_name="handle_document_pipeline",
            attempt=2,
            max_attempts=5,
            execution_token="stale-token",
            source_version=7,
        )
    ]
    assert calls[0][1] == {"stale_after_seconds": 123, "limit": 5}
