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

    def execute(self, statement, parameters=None):
        self.statement = statement
        self.parameters = parameters
        return FakeResult(self.row)


class FakeEngine:
    def __init__(self, row):
        self.connection = FakeConnection(row)

    def connect(self):
        return self.connection


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


def test_embedding_gate_queries_only_the_configured_model(monkeypatch):
    fake_engine = FakeEngine({"status": "complete"})
    monkeypatch.setattr(embedding_gate, "engine", lambda: fake_engine)
    monkeypatch.setattr(embedding_gate, "sql_text", lambda statement: statement)

    assert embedding_gate.latest_embedding_index_status("new-embed") == "complete"
    assert "WHERE embedding_model = :embedding_model" in fake_engine.connection.statement
    assert "ORDER BY updated_at DESC, id DESC" in fake_engine.connection.statement
    assert "completed_at" not in fake_engine.connection.statement
    assert fake_engine.connection.parameters == {"embedding_model": "new-embed"}
