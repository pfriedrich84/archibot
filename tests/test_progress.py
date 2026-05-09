from app.jobs import progress


class FakeConnection:
    def __init__(self, calls):
        self.calls = calls

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, traceback):
        return None

    def execute(self, statement, params):
        self.calls.append((statement, params))


class FakeEngine:
    def __init__(self, calls):
        self.calls = calls

    def begin(self):
        return FakeConnection(self.calls)


def test_update_pipeline_run_progress_persists_snapshot(monkeypatch):
    calls = []
    monkeypatch.setattr(progress, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(progress, "sql_text", lambda statement: statement)

    snapshot = progress.ProgressSnapshot(
        total=10, done=3, failed=1, skipped=2, phase="embedding", message="Embedding documents"
    )
    progress.update_pipeline_run_progress(42, snapshot)

    assert calls[0][1] == {
        "pipeline_run_id": 42,
        "total": 10,
        "done": 3,
        "failed": 1,
        "skipped": 2,
        "phase": "embedding",
        "message": "Embedding documents",
    }


def test_update_actor_execution_progress_persists_snapshot(monkeypatch):
    calls = []
    monkeypatch.setattr(progress, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(progress, "sql_text", lambda statement: statement)

    snapshot = progress.ProgressSnapshot(
        total=5, done=4, failed=0, skipped=0, phase="classify", message="Classifying"
    )
    progress.update_actor_execution_progress(9, snapshot, current_item="document:123")

    assert calls[0][1] == {
        "actor_execution_id": 9,
        "total": 5,
        "done": 4,
        "failed": 0,
        "skipped": 0,
        "current_item": "document:123",
        "message": "Classifying",
    }
