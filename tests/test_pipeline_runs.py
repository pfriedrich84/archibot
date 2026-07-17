from app.jobs import pipeline_runs


class FakeResult:
    def __init__(self, row):
        self.row = row

    def mappings(self):
        return self

    def first(self):
        return self.row


class FakeConnection:
    def __init__(self, calls, created=True):
        self.calls = calls
        self.created = created

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, traceback):
        return None

    def execute(self, statement, params):
        self.calls.append((statement, params))
        return FakeResult(
            {"id": 77, "status": params.get("status", "pending"), "created": self.created}
        )


class FakeEngine:
    def __init__(self, calls, created=True):
        self.calls = calls
        self.created = created

    def begin(self):
        return FakeConnection(self.calls, self.created)

    def connect(self):
        return FakeConnection(self.calls, self.created)


def test_upsert_document_pipeline_run_persists_blocked_run(monkeypatch):
    calls = []
    monkeypatch.setattr(pipeline_runs, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(pipeline_runs, "sql_text", lambda statement: statement)

    run = pipeline_runs.upsert_document_pipeline_run(
        trigger_source="webhook",
        paperless_document_id=42,
        paperless_modified="2026-05-08T12:00:00Z",
        content_hash=None,
        pipeline_dedupe_key="dedupe",
        status="blocked",
        blocked_reason="embedding_index_not_ready",
    )

    assert run == pipeline_runs.PipelineRunRecord(id=77, status="blocked", created=True)
    params = calls[0][1]
    assert params["trigger_source"] == "webhook"
    assert params["coalesced_trigger_source"] == "webhook"
    assert params["paperless_document_id"] == 42
    assert params["status"] == "blocked"
    assert params["progress_current_phase"] == "blocked"
    assert params["error_type"] == "embedding_index_not_ready"


def test_upsert_document_pipeline_run_persists_pending_run(monkeypatch):
    calls = []
    monkeypatch.setattr(pipeline_runs, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(pipeline_runs, "sql_text", lambda statement: statement)

    run = pipeline_runs.upsert_document_pipeline_run(
        trigger_source="poll",
        paperless_document_id=7,
        paperless_modified=None,
        content_hash="hash",
        pipeline_dedupe_key="dedupe",
        status="pending",
    )

    assert run == pipeline_runs.PipelineRunRecord(id=77, status="pending", created=True)
    params = calls[0][1]
    assert params["trigger_source"] == "poll"
    assert params["coalesced_trigger_source"] == "poll"
    assert params["progress_current_phase"] == "queued"
    assert params["error_type"] is None
    assert params["error"] is None


def test_load_document_pipeline_run_returns_document_fields(monkeypatch):
    calls = []

    class LoadConnection(FakeConnection):
        def execute(self, statement, params):
            self.calls.append((statement, params))
            return FakeResult(
                {
                    "id": 42,
                    "status": "queued",
                    "paperless_document_id": 99,
                    "paperless_modified": None,
                    "content_hash": "hash",
                    "retry_count": 2,
                    "max_retries": 5,
                }
            )

    class LoadEngine(FakeEngine):
        def connect(self):
            return LoadConnection(self.calls)

    monkeypatch.setattr(pipeline_runs, "engine", lambda: LoadEngine(calls))
    monkeypatch.setattr(pipeline_runs, "sql_text", lambda statement: statement)

    assert pipeline_runs.load_document_pipeline_run(42) == pipeline_runs.DocumentPipelineRunRecord(
        id=42,
        status="queued",
        paperless_document_id=99,
        paperless_modified=None,
        content_hash="hash",
        retry_count=2,
        max_retries=5,
    )


def test_cancel_check_treats_already_cancelled_run_as_terminal(monkeypatch):
    calls = []
    monkeypatch.setattr(pipeline_runs, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(pipeline_runs, "sql_text", lambda statement: statement)

    assert pipeline_runs.is_pipeline_run_cancel_requested(42) is True
    assert "status IN ('cancel_requested', 'cancelled')" in calls[0][0]


def test_mark_pipeline_run_cancelled_finalizes_cancel_request(monkeypatch):
    calls = []
    monkeypatch.setattr(pipeline_runs, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(pipeline_runs, "sql_text", lambda statement: statement)

    pipeline_runs.mark_pipeline_run_cancelled(42)

    assert calls[0][1] == {
        "pipeline_run_id": 42,
        "message": "Pipeline run cancelled by admin request.",
    }


def test_list_due_retrying_document_pipeline_run_ids_returns_due_runs(monkeypatch):
    calls = []

    class RetryConnection(FakeConnection):
        def execute(self, statement, params):
            self.calls.append((statement, params))
            return FakeRows([{"id": 10}, {"id": 11}])

    class RetryEngine(FakeEngine):
        def connect(self):
            return RetryConnection(self.calls)

    class FakeRows:
        def __init__(self, rows):
            self.rows = rows

        def mappings(self):
            return self

        def all(self):
            return self.rows

    monkeypatch.setattr(pipeline_runs, "engine", lambda: RetryEngine(calls))
    monkeypatch.setattr(pipeline_runs, "sql_text", lambda statement: statement)

    assert pipeline_runs.list_due_retrying_document_pipeline_run_ids(limit=2) == [10, 11]
    assert calls[0][1] == {"limit": 2}


def test_mark_pipeline_run_retrying_schedules_backoff(monkeypatch):
    calls = []
    monkeypatch.setattr(pipeline_runs, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(pipeline_runs, "sql_text", lambda statement: statement)

    pipeline_runs.mark_pipeline_run_retrying(
        42,
        retry_class="transient_paperless",
        retry_reason="TimeoutError",
        backoff_seconds=30,
        phase="document_actor",
        message="Retry scheduled.",
    )

    assert calls[0][1] == {
        "pipeline_run_id": 42,
        "retry_class": "transient_paperless",
        "retry_reason": "TimeoutError",
        "backoff_seconds": 30,
        "phase": "document_actor",
        "message": "Retry scheduled.",
    }


def test_mark_pipeline_run_status_updates_operator_state(monkeypatch):
    calls = []
    monkeypatch.setattr(pipeline_runs, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(pipeline_runs, "sql_text", lambda statement: statement)

    pipeline_runs.mark_pipeline_run_status(
        42,
        status="running",
        phase="document_actor",
        message="Running",
    )

    assert calls[0][1] == {
        "pipeline_run_id": 42,
        "status": "running",
        "status_for_lifecycle": "running",
        "phase": "document_actor",
        "message": "Running",
        "error_type": None,
        "error": None,
    }


def test_mark_pipeline_run_pending_clears_blocked_state(monkeypatch):
    calls = []
    monkeypatch.setattr(pipeline_runs, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(pipeline_runs, "sql_text", lambda statement: statement)

    pipeline_runs.mark_pipeline_run_pending(42)

    statement = calls[0][0]
    assert "finished_at = NULL" in statement
    assert calls[0][1] == {"pipeline_run_id": 42, "message": "Waiting for document actor."}


def test_upsert_document_pipeline_run_reports_coalesced_run(monkeypatch):
    calls = []
    monkeypatch.setattr(pipeline_runs, "engine", lambda: FakeEngine(calls, created=False))
    monkeypatch.setattr(pipeline_runs, "sql_text", lambda statement: statement)

    run = pipeline_runs.upsert_document_pipeline_run(
        trigger_source="poll",
        paperless_document_id=7,
        paperless_modified=None,
        content_hash="hash",
        pipeline_dedupe_key="dedupe",
        status="pending",
        webhook_delivery_id=10,
        command_id=11,
        requested_by_user_id=12,
    )

    assert run == pipeline_runs.PipelineRunRecord(id=77, status="pending", created=False)
    statement = calls[0][0]
    assert "jsonb_build_array(CAST(:coalesced_trigger_source AS text))" in statement
    assert (
        "pipeline_runs.coalesced_sources::jsonb ? CAST(:coalesced_trigger_source AS text)"
        in statement
    )
    assert "requested_by_user_id" in statement
    params = calls[0][1]
    assert params["webhook_delivery_id"] == 10
    assert params["command_id"] == 11
    assert params["requested_by_user_id"] == 12
