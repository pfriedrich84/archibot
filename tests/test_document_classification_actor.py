from types import SimpleNamespace

import pytest

from app.actors import document
from app.models import ClassificationResult


@pytest.mark.asyncio
async def test_classify_document_fetches_entities_and_calls_classifier(monkeypatch):
    calls = []
    target = SimpleNamespace(id=42, title="Doc", content="Text")

    class FakePaperless:
        async def list_correspondents(self):
            return ["corr"]

        async def list_document_types(self):
            return ["doctype"]

        async def list_storage_paths(self):
            return ["storage"]

        async def list_tags(self):
            return ["tag"]

    class FakeAiProvider:
        async def embed(self, text):
            return [0.1, 0.2]

    async def fake_find_similar(*args, **kwargs):
        return []

    async def fake_classify(*args):
        calls.append(args)
        return ClassificationResult(title="Classified", confidence=91), "{}"

    async def fake_judge(*args, **kwargs):
        return SimpleNamespace(
            result=args[1], verdict="skipped", reasoning=None, original_proposed_json=None
        )

    monkeypatch.setattr(document, "find_similar_with_precomputed_embedding", fake_find_similar)
    monkeypatch.setattr(document, "classify", fake_classify)
    monkeypatch.setattr(document, "maybe_run_judge", fake_judge)

    outcome = await document._classify_document(
        target,
        paperless=FakePaperless(),
        ai_provider=FakeAiProvider(),
    )

    assert outcome.result.title == "Classified"
    assert outcome.raw_response == "{}"
    assert outcome.context_documents == []
    assert calls[0][0] is target
    assert calls[0][1] == []
    assert calls[0][2] == ["corr"]
    assert calls[0][3] == ["doctype"]
    assert calls[0][4] == ["storage"]
    assert calls[0][5] == ["tag"]
    assert isinstance(calls[0][6], FakeAiProvider)
