from __future__ import annotations

from contextlib import nullcontext
from types import SimpleNamespace

import pytest

from app.jobs.document_embeddings import document_embedding_text
from app.models import PaperlessDocument
from app.temporal import document_phase_activities
from app.temporal.contracts import (
    DocumentPhaseRequest,
    DocumentReviewCompletion,
    ModelPhaseConfiguration,
)


def _configuration() -> ModelPhaseConfiguration:
    return ModelPhaseConfiguration(
        phase="embedding",
        provider_type="ollama",
        provider_base_url="http://provider",
        model_id="embed",
        configuration_revision="revision",
        task_queue="archibot-model-embedding",
        embedding_model="embed",
        classification_model="classify",
        ocr_text_model="ocr-text",
        ocr_vision_model="ocr-vision",
        judge_model="judge",
        ocr_mode="text",
        judge_enabled=True,
        judge_confidence_threshold=50,
        embedding_num_ctx=2048,
        classification_num_ctx=8192,
        ocr_num_ctx=16384,
        ocr_requested_tag_id=124,
    )


@pytest.mark.asyncio
async def test_embedding_phase_embeds_and_persists_cached_ocr_text(monkeypatch):
    original = PaperlessDocument(id=261, title="Invoice", content="broken", tags=[124])
    stored = []

    class Provider:
        def __init__(self):
            self.text = None
            self.closed = False

        async def embed(self, text):
            self.text = text
            return [0.1, 0.2]

        async def aclose(self):
            self.closed = True

    class Paperless:
        def __init__(self):
            self.closed = False

        async def aclose(self):
            self.closed = True

    provider = Provider()
    paperless = Paperless()

    def load_state(_pipeline_run_id, _cycle, phase):
        return {"status": "completed"} if phase == "ocr" else None

    async def document_for(_request):
        return ({"paperless_document_id": 261}, paperless, original)

    monkeypatch.setattr(document_phase_activities, "_load_state", load_state)
    monkeypatch.setattr(document_phase_activities, "_document_for", document_for)
    monkeypatch.setattr(document_phase_activities, "_provider", lambda _request: provider)
    monkeypatch.setattr(
        document_phase_activities, "cached_ocr_correction", lambda _document_id: "corrected text"
    )
    monkeypatch.setattr(
        document_phase_activities, "document_embedding_exists", lambda **_kwargs: False
    )
    monkeypatch.setattr(
        document_phase_activities,
        "store_document_embedding",
        lambda item: stored.append(item) or "hash",
    )
    monkeypatch.setattr(document_phase_activities, "_upsert_phase_state", lambda *_a, **_k: None)
    monkeypatch.setattr(document_phase_activities.activity, "heartbeat", lambda _details: None)

    result = await document_phase_activities.process_document_embedding_phase(
        DocumentPhaseRequest(12, 12, _configuration())
    )

    assert result.status == "completed"
    assert provider.text == document_embedding_text("Invoice", "corrected text")
    assert stored[0].content == "corrected text"
    assert provider.closed is True
    assert paperless.closed is True


class _ProjectionConnection:
    def __init__(self):
        self.calls = []

    def execute(self, statement, parameters=None):
        self.calls.append((str(statement), parameters or {}))
        return SimpleNamespace(rowcount=1)


class _ProjectionEngine:
    def __init__(self):
        self.connection = _ProjectionConnection()

    def begin(self):
        return nullcontext(self.connection)


def test_review_completion_projects_terminal_document_state_once(monkeypatch):
    fake_engine = _ProjectionEngine()
    monkeypatch.setattr(document_phase_activities, "engine", lambda: fake_engine)
    monkeypatch.setattr(document_phase_activities, "sql_text", lambda statement: statement)

    document_phase_activities._finish_document_review_projection(
        DocumentReviewCompletion(12, 34, "committed")
    )

    update = fake_engine.connection.calls[0]
    event = fake_engine.connection.calls[1]
    assert update[1]["status"] == "succeeded"
    assert update[1]["phase"] == "done"
    assert "progress_current_phase IN ('awaiting_review', 'review_suggestion')" in update[0]
    assert "document.workflow.completed" in event[0]
    assert '"review_suggestion_id":34' in event[1]["payload"]
