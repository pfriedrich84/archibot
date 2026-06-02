"""Tests for pgvector-backed context builder compatibility facade."""

from types import SimpleNamespace

import pytest

from app.models import PaperlessDocument
from app.pipeline import context_builder
from app.pipeline.context_types import SimilarDocument


def test_document_summary_is_bounded(monkeypatch):
    monkeypatch.setattr(context_builder.settings, "embed_max_chars", 8)

    doc = PaperlessDocument(id=1, title="Title", content="Content")

    assert context_builder.document_summary(doc) == "Title\nCo"


def test_store_embedding_delegates_to_pgvector(monkeypatch):
    stored = []
    monkeypatch.setattr(context_builder.settings, "ollama_embed_model", "embed-model")
    monkeypatch.setattr(
        context_builder, "store_document_embedding", lambda item: stored.append(item)
    )

    doc = PaperlessDocument(
        id=42,
        title="Doc",
        content="Text",
        correspondent=10,
        document_type=20,
        storage_path=30,
        tags=[2, 3],
        created_date="2026-05-27",
    )
    context_builder.store_embedding(doc, [0.1, 0.2])

    assert stored[0].paperless_document_id == 42
    assert stored[0].embedding_model == "embed-model"
    assert stored[0].trusted_for_context is True
    assert stored[0].tags == [2, 3]


@pytest.mark.asyncio
async def test_find_similar_with_precomputed_embedding_delegates(monkeypatch):
    expected = [SimilarDocument(document=PaperlessDocument(id=7, title="Context"), distance=0.1)]

    async def fake_find(doc, embedding, paperless, limit=None):
        assert doc.id == 42
        assert embedding == [0.1]
        assert limit == 3
        return expected

    monkeypatch.setattr(context_builder, "_find_similar_with_precomputed_embedding", fake_find)

    result = await context_builder.find_similar_with_precomputed_embedding(
        PaperlessDocument(id=42, title="Target"), [0.1], SimpleNamespace(), limit=3
    )

    assert result == expected


@pytest.mark.asyncio
async def test_find_similar_by_query_text_filtered_is_vector_only(monkeypatch):
    calls = []
    monkeypatch.setattr(context_builder.settings, "context_max_docs", 5)
    monkeypatch.setattr(context_builder.settings, "context_max_distance", 0.4)

    class FakeOllama:
        async def embed(self, text):
            calls.append(("embed", text))
            return [0.1, 0.2]

    monkeypatch.setattr(
        context_builder,
        "find_similar_document_ids",
        lambda *args, **kwargs: calls.append(("find", kwargs)) or [(7, 0.2)],
    )

    class FakePaperless:
        async def get_document(self, doc_id):
            return PaperlessDocument(id=doc_id, title="Context", content="Text")

    result = await context_builder.find_similar_by_query_text_filtered(
        "invoice", FakePaperless(), FakeOllama(), correspondent_id=10, doctype_id=20
    )

    assert result == [
        SimilarDocument(
            document=PaperlessDocument(id=7, title="Context", content="Text"), distance=0.2
        )
    ]
    assert calls[0] == ("embed", "invoice")
    assert calls[1][0] == "find"
    assert calls[1][1]["correspondent_id"] == 10
    assert calls[1][1]["doctype_id"] == 20


def test_find_similar_by_id_delegates(monkeypatch):
    monkeypatch.setattr(
        context_builder, "_find_similar_by_id", lambda document_id, limit: [(8, 0.3)]
    )

    assert context_builder.find_similar_by_id(42, limit=4) == [(8, 0.3)]
