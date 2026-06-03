from app.jobs import commands


class FakeRows:
    def __init__(self, rows):
        self.rows = rows

    def mappings(self):
        return self

    def all(self):
        return self.rows


class FakeConnection:
    def __init__(self, calls, rows=None):
        self.calls = calls
        self.rows = rows or []

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, traceback):
        return None

    def execute(self, statement, params):
        self.calls.append((statement, params))
        return FakeRows(self.rows)


class FakeEngine:
    def __init__(self, calls, rows=None):
        self.calls = calls
        self.rows = rows or []

    def connect(self):
        return FakeConnection(self.calls, self.rows)

    def begin(self):
        return FakeConnection(self.calls, self.rows)


def test_list_pending_embedding_build_commands_returns_payload(monkeypatch):
    calls = []
    rows = [
        {
            "id": 5,
            "type": "embedding_index_build",
            "status": "pending",
            "payload": {"limit": 10},
        }
    ]
    monkeypatch.setattr(commands, "engine", lambda: FakeEngine(calls, rows))
    monkeypatch.setattr(commands, "sql_text", lambda statement: statement)

    assert commands.list_pending_embedding_build_commands(limit=2) == [
        commands.CommandRecord(
            id=5,
            type="embedding_index_build",
            status="pending",
            payload={"limit": 10},
        )
    ]
    assert calls[0][1] == {"command_type": "embedding_index_build", "limit": 2}


def test_list_pending_poll_reconciliation_commands_returns_payload(monkeypatch):
    calls = []
    rows = [
        {
            "id": 6,
            "type": "poll_reconciliation",
            "status": "pending",
            "payload": {"limit": 25},
        }
    ]
    monkeypatch.setattr(commands, "engine", lambda: FakeEngine(calls, rows))
    monkeypatch.setattr(commands, "sql_text", lambda statement: statement)

    assert commands.list_pending_poll_reconciliation_commands(limit=3) == [
        commands.CommandRecord(
            id=6,
            type="poll_reconciliation",
            status="pending",
            payload={"limit": 25},
        )
    ]
    assert calls[0][1] == {"command_type": "poll_reconciliation", "limit": 3}


def test_list_pending_reindex_commands_returns_payload(monkeypatch):
    calls = []
    rows = [
        {
            "id": 7,
            "type": "reindex",
            "status": "pending",
            "payload": {"limit": 50},
        }
    ]
    monkeypatch.setattr(commands, "engine", lambda: FakeEngine(calls, rows))
    monkeypatch.setattr(commands, "sql_text", lambda statement: statement)

    assert commands.list_pending_reindex_commands(limit=4) == [
        commands.CommandRecord(
            id=7,
            type="reindex",
            status="pending",
            payload={"limit": 50},
        )
    ]
    assert calls[0][1] == {"command_type": "reindex", "limit": 4}


def test_mark_command_status_updates_bridge_status(monkeypatch):
    calls = []
    monkeypatch.setattr(commands, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(commands, "sql_text", lambda statement: statement)

    commands.mark_command_status(5, "queued")

    statement = calls[0][0]
    assert "SET status = CAST(:status AS character varying)" in statement
    assert "CAST(:status_for_lifecycle AS character varying) IN ('queued', 'running')" in statement
    assert calls[0][1] == {
        "command_id": 5,
        "status": "queued",
        "status_for_lifecycle": "queued",
        "error": None,
    }
