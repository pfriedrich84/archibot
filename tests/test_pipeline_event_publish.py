from app.events import publish


class FakeConnection:
    def __init__(self):
        self.calls = []

    def execute(self, statement, params):
        self.calls.append((statement, params))


class FakeBegin:
    def __init__(self, connection):
        self.connection = connection

    def __enter__(self):
        return self.connection

    def __exit__(self, exc_type, exc, tb):
        return False


class FakeEngine:
    def __init__(self):
        self.connection = FakeConnection()

    def begin(self):
        return FakeBegin(self.connection)


def test_publish_pipeline_event_logs_string_levels_without_structlog_type_error(monkeypatch):
    fake_engine = FakeEngine()
    monkeypatch.setattr(publish, "engine", lambda: fake_engine)
    monkeypatch.setattr(publish, "sql_text", lambda statement: statement)

    publish.publish_pipeline_event(
        "embedding.index.started",
        level="warning",
        message="Embedding index started.",
        payload={"embedding_index_state_id": 12},
    )

    assert fake_engine.connection.calls[0][1]["level"] == "warning"


def test_success_pipeline_event_level_is_mirrored_as_info_log(monkeypatch):
    fake_engine = FakeEngine()
    calls = []

    class FakeLog:
        def bind(self, **kwargs):
            calls.append(("bind", kwargs))
            return self

        def info(self, message):
            calls.append(("info", message))

    monkeypatch.setattr(publish, "engine", lambda: fake_engine)
    monkeypatch.setattr(publish, "sql_text", lambda statement: statement)
    monkeypatch.setattr(publish, "log", FakeLog())

    publish.publish_pipeline_event("embedding.index.completed", level="success")

    assert fake_engine.connection.calls[0][1]["level"] == "success"
    assert calls[-1] == ("info", "embedding.index.completed")
