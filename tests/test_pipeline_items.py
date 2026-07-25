from app import execution_lifecycle
from app.jobs import pipeline_items


class FakeResult:
    def __init__(self, row=None):
        self.row = row
        self.rowcount = 1

    def mappings(self):
        return self

    def first(self):
        return self.row

    def all(self):
        return [self.row]


class FakeConnection:
    def __init__(self, calls, row=None):
        self.calls = calls
        self.row = row or {
            "id": 5,
            "status": "running",
            "total": 3,
            "done": 1,
            "failed": 1,
            "skipped": 0,
        }

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, traceback):
        return None

    def execute(self, statement, params):
        self.calls.append((statement, params))
        return FakeResult(self.row)


class FakeEngine:
    def __init__(self, calls, row=None):
        self.calls = calls
        self.row = row

    def begin(self):
        return FakeConnection(self.calls, self.row)

    def connect(self):
        return FakeConnection(self.calls, self.row)


def test_start_pipeline_item_creates_running_item(monkeypatch):
    calls = []
    monkeypatch.setattr(pipeline_items, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(pipeline_items, "sql_text", lambda statement: statement)

    item = pipeline_items.start_pipeline_item(
        pipeline_run_id=1,
        item_type="paperless_fetch",
        paperless_document_id=42,
    )

    assert item == pipeline_items.PipelineItemRecord(id=5, status="running")
    assert calls[0][1]["pipeline_run_id"] == 1
    assert calls[0][1]["item_type"] == "paperless_fetch"
    assert calls[0][1]["paperless_document_id"] == 42


def test_finish_pipeline_item_updates_status(monkeypatch):
    calls = []
    monkeypatch.setattr(pipeline_items, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(pipeline_items, "sql_text", lambda statement: statement)

    pipeline_items.finish_pipeline_item(5, status="succeeded")

    assert calls[0][1] == {"item_id": 5, "status": "succeeded", "error": None}


def test_progress_from_pipeline_items_derives_counts(monkeypatch):
    calls = []
    monkeypatch.setattr(pipeline_items, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(pipeline_items, "sql_text", lambda statement: statement)

    assert pipeline_items.progress_from_pipeline_items(1) == (3, 1, 1, 0)
    assert calls[0][1] == {"pipeline_run_id": 1}


def test_staged_pipeline_item_start_and_finish_are_attempt_fenced(monkeypatch):
    calls = []
    row = {"id": 6, "status": "running", "attempt": 2}
    monkeypatch.setattr(pipeline_items, "engine", lambda: FakeEngine(calls, row))
    monkeypatch.setattr(pipeline_items, "sql_text", lambda statement: statement)
    token = execution_lifecycle.set_invocation_fence(
        execution_lifecycle.InvocationFence(
            actor_name="process_staged_document_batch",
            execution_actor_name="process_staged_document_batch",
            source_kind="command",
            source_id=55,
            execution_token="batch-token",
            source_version=3,
            actor_execution_id=7,
            attempt=1,
        )
    )
    try:
        item = pipeline_items.start_or_resume_staged_pipeline_item(
            pipeline_run_id=1,
            batch_command_id=55,
            item_type="classification",
            item_key="classification:42",
            paperless_document_id=42,
        )
        pipeline_items.finish_staged_pipeline_item(
            item,
            pipeline_run_id=1,
            batch_command_id=55,
            status="succeeded",
        )
    finally:
        execution_lifecycle.reset_invocation_fence(token)

    assert "FOR SHARE" in calls[0][0]
    assert "active_actor_token = :execution_token" in calls[1][0]
    assert "status NOT IN ('cancel_requested', 'cancelled')" in calls[1][0]
    assert "FOR SHARE" in calls[2][0]
    assert "attempt = :attempt" in calls[3][0]
    assert calls[3][1]["attempt"] == 2
    assert calls[3][1]["execution_token"] == "batch-token"


def test_start_or_resume_pipeline_item_uses_stable_item_key(monkeypatch):
    calls = []
    row = {"id": 6, "status": "running", "attempt": 2}
    monkeypatch.setattr(pipeline_items, "engine", lambda: FakeEngine(calls, row))
    monkeypatch.setattr(pipeline_items, "sql_text", lambda statement: statement)

    item = pipeline_items.start_or_resume_pipeline_item(
        pipeline_run_id=1,
        item_type="classification",
        item_key="classification:42",
        paperless_document_id=42,
    )

    assert item == pipeline_items.PipelineItemRecord(id=6, status="running", attempt=2)
    statement, params = calls[0]
    assert "ON CONFLICT (pipeline_run_id, item_key)" in statement
    assert params["item_key"] == "classification:42"
