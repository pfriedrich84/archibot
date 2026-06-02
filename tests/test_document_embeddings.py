import pytest

from app.jobs import document_embeddings
from app.models import PaperlessDocument
from app.pipeline.context_types import SimilarDocument


class FakeResult:
    def __init__(self, rows=None):
        self.rows = rows or []

    def mappings(self):
        return self

    def all(self):
        return self.rows

    def first(self):
        return self.rows[0] if self.rows else None


class FakeConnection:
    def __init__(self, calls, rows=None):
        self.calls = calls
        self.rows = rows or []

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, traceback):
        return None

    def execute(self, statement, params=None):
        self.calls.append((statement, params or {}))
        return FakeResult(self.rows)


class FakeEngine:
    def __init__(self, calls, rows=None):
        self.calls = calls
        self.rows = rows or []

    def begin(self):
        return FakeConnection(self.calls, self.rows)


def test_document_embedding_text_is_bounded(monkeypatch):
    monkeypatch.setattr(document_embeddings.settings, "embed_max_chars", 8)

    assert document_embeddings.document_embedding_text("Title", "Content") == "Title\nCo"


def test_store_document_embedding_persists_pgvector_metadata(monkeypatch):
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
            correspondent_id=10,
            document_type_id=20,
            storage_path_id=30,
            tags=[1, 2],
            paperless_modified="2026-05-27T12:00:00Z",
            trusted_for_context=True,
        )
    )

    assert content_hash == document_embeddings.content_hash_for_text("Title\nContent")
    params = calls[0][1]
    assert params["paperless_document_id"] == 42
    assert params["embedding_model"] == "embed-model"
    assert params["dimensions"] == 2
    assert params["embedding"] == "[0.1,0.2]"
    assert params["correspondent_id"] == 10
    assert params["document_type_id"] == 20
    assert params["storage_path_id"] == 30
    assert params["tags_json"] == "[1, 2]"
    assert params["trusted_for_context"] is True


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


def test_find_similar_document_ids_uses_pgvector_trusted_filters(monkeypatch):
    calls = []
    monkeypatch.setattr(
        document_embeddings,
        "engine",
        lambda: FakeEngine(calls, [{"paperless_document_id": 7, "distance": 0.12}]),
    )
    monkeypatch.setattr(document_embeddings, "sql_text", lambda statement: statement)
    monkeypatch.setattr(document_embeddings.settings, "ollama_embed_model", "embed-model")

    rows = document_embeddings.find_similar_document_ids(
        [0.1, 0.2], exclude_id=42, limit=5, max_distance=0.5
    )

    assert rows == [(7, 0.12)]
    statement, params = calls[0]
    assert "embedding <-> CAST(:embedding AS vector)" in statement
    assert "trusted_for_context = TRUE" in statement
    assert "paperless_document_id != :exclude_id" in statement
    assert "distance <= :max_distance" in statement
    assert params["exclude_id"] == 42
    assert params["limit"] == 5
    assert params["embedding_model"] == "embed-model"
    assert params["dimensions"] == 2


def test_is_trusted_document_uses_absence_of_inbox_tag(monkeypatch):
    monkeypatch.setattr(document_embeddings.settings, "paperless_inbox_tag_id", 99)

    assert document_embeddings.is_trusted_document(PaperlessDocument(id=1, title="A", tags=[1]))
    assert not document_embeddings.is_trusted_document(
        PaperlessDocument(id=2, title="B", tags=[99, 1])
    )


@pytest.mark.asyncio
async def test_find_similar_filters_loaded_inbox_documents(monkeypatch):
    monkeypatch.setattr(document_embeddings.settings, "paperless_inbox_tag_id", 99)
    monkeypatch.setattr(
        document_embeddings,
        "find_similar_document_ids",
        lambda *args, **kwargs: [(7, 0.1), (8, 0.2)],
    )

    class FakePaperless:
        async def get_document(self, doc_id):
            tags = [99] if doc_id == 8 else []
            return PaperlessDocument(id=doc_id, title=f"Doc {doc_id}", content="Text", tags=tags)

    results = await document_embeddings.find_similar_with_precomputed_embedding(
        PaperlessDocument(id=42, title="Target"), [0.1], FakePaperless()
    )

    assert results == [
        SimilarDocument(
            document=PaperlessDocument(id=7, title="Doc 7", content="Text"), distance=0.1
        )
    ]
