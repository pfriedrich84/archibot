from app.jobs import embedding_gate


class FakeResult:
    def __init__(self, row):
        self.row = row

    def mappings(self):
        return self

    def first(self):
        return self.row


class FakeConnection:
    def __init__(self, row):
        self.row = row

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, traceback):
        return None

    def execute(self, statement):
        return FakeResult(self.row)


class FakeEngine:
    def __init__(self, row):
        self.row = row

    def connect(self):
        return FakeConnection(self.row)


def test_embedding_gate_allows_only_complete_status(monkeypatch):
    monkeypatch.setattr(embedding_gate, "engine", lambda: FakeEngine({"status": "complete"}))
    monkeypatch.setattr(embedding_gate, "sql_text", lambda statement: statement)

    assert embedding_gate.latest_embedding_index_status() == "complete"
    assert embedding_gate.ensure_embedding_index_ready() is True


def test_embedding_gate_fails_closed_without_state(monkeypatch):
    monkeypatch.setattr(embedding_gate, "engine", lambda: FakeEngine(None))
    monkeypatch.setattr(embedding_gate, "sql_text", lambda statement: statement)

    assert embedding_gate.latest_embedding_index_status() is None
    assert embedding_gate.ensure_embedding_index_ready() is False


def test_embedding_gate_fails_closed_for_incomplete_status(monkeypatch):
    monkeypatch.setattr(embedding_gate, "engine", lambda: FakeEngine({"status": "building"}))
    monkeypatch.setattr(embedding_gate, "sql_text", lambda statement: statement)

    assert embedding_gate.latest_embedding_index_status() == "building"
    assert embedding_gate.ensure_embedding_index_ready() is False
