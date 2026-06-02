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

        async def aclose(self):
            return None

    class FakeOllama:
        async def aclose(self):
            return None

    async def fake_classify(*args):
        calls.append(args)
        return ClassificationResult(title="Classified", confidence=91), "{}"

    monkeypatch.setattr(document, "PaperlessClient", FakePaperless)
    monkeypatch.setattr(document, "OllamaClient", FakeOllama)
    monkeypatch.setattr(document, "classify", fake_classify)

    outcome = await document._classify_document(target)

    assert outcome.result.title == "Classified"
    assert outcome.raw_response == "{}"
    assert outcome.document is target
    assert outcome.context_documents == []
    assert outcome.context_count == 0
    assert calls[0][0] is target
    assert calls[0][1] == []
    assert calls[0][2] == ["corr"]
    assert calls[0][3] == ["doctype"]
    assert calls[0][4] == ["storage"]
    assert calls[0][5] == ["tag"]
    assert isinstance(calls[0][6], FakeOllama)
