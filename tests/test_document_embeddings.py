from app.jobs import document_embeddings


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


def test_document_embedding_text_is_bounded(monkeypatch):
    monkeypatch.setattr(document_embeddings.settings, "embed_max_chars", 8)

    assert document_embeddings.document_embedding_text("Title", "Content") == "Title\nCo"


def test_store_document_embedding_persists_pgvector(monkeypatch):
    calls = []
    monkeypatch.setattr(document_embeddings, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(document_embeddings, "sql_text", lambda statement: statement)

    content_hash = document_embeddings.store_document_embedding(
        document_embeddings.DocumentEmbeddingInput(
            paperless_document_id=42,
            title="Title",
            content="Content",
            embedding_model="embed-model",
            embedding=[0.1, 0.2],
        )
    )

    assert content_hash == document_embeddings.content_hash_for_text("Title\nContent")
    assert calls[0][1]["paperless_document_id"] == 42
    assert calls[0][1]["embedding_model"] == "embed-model"
    assert calls[0][1]["dimensions"] == 2
    assert calls[0][1]["embedding"] == "[0.1,0.2]"


def test_store_document_embedding_skips_empty_text():
    assert (
        document_embeddings.store_document_embedding(
            document_embeddings.DocumentEmbeddingInput(
                paperless_document_id=42,
                title="",
                content="",
                embedding_model="embed-model",
                embedding=[0.1],
            )
        )
        is None
    )
