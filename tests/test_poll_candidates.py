from types import SimpleNamespace

from app.jobs import poll_candidates


class _Connection:
    def __init__(self, rowcounts):
        self.rowcounts = iter(rowcounts)
        self.params = []

    def execute(self, statement, params):
        self.params.append(params)
        return SimpleNamespace(rowcount=next(self.rowcounts))


class _Begin:
    def __init__(self, connection):
        self.connection = connection

    def __enter__(self):
        return self.connection

    def __exit__(self, *args):
        return False


class _Engine:
    def __init__(self, connection):
        self.connection = connection

    def begin(self):
        return _Begin(self.connection)


def test_poll_candidate_protocol_is_deterministic_across_discovery_replay(monkeypatch):
    connection = _Connection([1, 0])
    monkeypatch.setattr(poll_candidates, "engine", lambda: _Engine(connection))
    kwargs = {
        "command_id": 9,
        "paperless_document_id": 42,
        "discovered_modified": "2026-05-08T12:00:00Z",
        "marker_disposition": "unclassified",
        "force": True,
    }

    first = poll_candidates.persist_poll_candidate(**kwargs)
    replay = poll_candidates.persist_poll_candidate(**kwargs)

    assert first.created is True
    assert replay.created is False
    assert first.candidate_id == replay.candidate_id
    assert connection.params[0]["protocol_version"] == 1
    assert connection.params[0]["idempotency_key"] == connection.params[1]["idempotency_key"]
