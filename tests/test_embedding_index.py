from app.jobs import embedding_index


class FakeResult:
    def __init__(self, row=None):
        self.row = row

    def mappings(self):
        return self

    def first(self):
        return self.row


class FakeConnection:
    def __init__(self, calls, running=None):
        self.calls = calls
        self.running = running

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, traceback):
        return None

    def execute(self, statement, params=None):
        params = params or {}
        self.calls.append((statement, params))
        if "WHERE status = 'building'" in statement:
            return FakeResult(self.running)
        return FakeResult({"id": 55, "status": params.get("status", "building")})


class FakeEngine:
    def __init__(self, calls, running=None):
        self.calls = calls
        self.running = running

    def begin(self):
        return FakeConnection(self.calls, self.running)


def test_start_embedding_index_build_creates_building_state(monkeypatch):
    calls = []
    monkeypatch.setattr(embedding_index, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(embedding_index, "sql_text", lambda statement: statement)

    build = embedding_index.start_embedding_index_build(
        embedding_model="embed-model",
        dimensions=1024,
        content_scope="trusted_documents_without_inbox_tag",
        document_count=10,
    )

    assert build == embedding_index.EmbeddingIndexBuild(id=55, status="building")
    assert calls[1][1] == {
        "embedding_model": "embed-model",
        "dimensions": 1024,
        "content_scope": "trusted_documents_without_inbox_tag",
        "document_count": 10,
    }


def test_start_embedding_index_build_returns_existing_build(monkeypatch):
    calls = []
    monkeypatch.setattr(
        embedding_index,
        "engine",
        lambda: FakeEngine(calls, running={"id": 77, "status": "building"}),
    )
    monkeypatch.setattr(embedding_index, "sql_text", lambda statement: statement)

    build = embedding_index.start_embedding_index_build(
        embedding_model="embed-model",
        dimensions=1024,
        content_scope="trusted_documents_without_inbox_tag",
        document_count=10,
    )

    assert build == embedding_index.EmbeddingIndexBuild(
        id=77, status="building", already_running=True
    )
    assert len(calls) == 1


def test_update_embedding_index_progress_persists_counts(monkeypatch):
    calls = []
    monkeypatch.setattr(embedding_index, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(embedding_index, "sql_text", lambda statement: statement)

    embedding_index.update_embedding_index_progress(
        55, document_count=10, embedded_count=3, failed_count=1
    )

    assert calls[0][1] == {
        "build_id": 55,
        "document_count": 10,
        "embedded_count": 3,
        "failed_count": 1,
    }


def test_finish_embedding_index_build_updates_status(monkeypatch):
    calls = []
    monkeypatch.setattr(embedding_index, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(embedding_index, "sql_text", lambda statement: statement)

    embedding_index.finish_embedding_index_build(55, status="failed", error="not migrated")

    assert "CAST(:status AS character varying)" in calls[0][0]
    assert calls[0][1] == {"build_id": 55, "status": "failed", "error": "not migrated"}
