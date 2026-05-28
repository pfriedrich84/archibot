from types import SimpleNamespace

from app.actors import document
from app.jobs.actor_execution import ActorExecutionHandle
from app.jobs.pipeline_items import PipelineItemRecord
from app.jobs.pipeline_runs import DocumentPipelineRunRecord
from app.jobs.review_suggestions import StoredReviewSuggestion
from app.models import ClassificationResult


def test_document_actor_fetches_classifies_and_stores_review_suggestion(monkeypatch):
    statuses = []
    item_finishes = []
    pipeline_progress = []
    actor_progress = []
    finishes = []
    events = []

    monkeypatch.setattr(
        document,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=22, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )
    monkeypatch.setattr(
        document,
        "load_document_pipeline_run",
        lambda pipeline_run_id: DocumentPipelineRunRecord(
            id=pipeline_run_id,
            status="queued",
            paperless_document_id=42,
            paperless_modified=None,
            content_hash=None,
            retry_count=0,
            max_retries=5,
        ),
    )
    monkeypatch.setattr(document, "ensure_embedding_index_ready", lambda: True)
    monkeypatch.setattr(
        document,
        "start_pipeline_item",
        lambda **kwargs: PipelineItemRecord(id=9, status="running"),
    )
    monkeypatch.setattr(
        document,
        "finish_pipeline_item",
        lambda *args, **kwargs: item_finishes.append((args, kwargs)),
    )
    monkeypatch.setattr(
        document, "progress_from_pipeline_items", lambda pipeline_run_id: (1, 1, 0, 0)
    )

    class FakeCoroutine:
        def close(self):
            return None

    monkeypatch.setattr(
        document, "_fetch_paperless_document", lambda paperless_document_id: FakeCoroutine()
    )
    fetched_document = SimpleNamespace(title="Doc", content="Text")
    run_async_results = [
        fetched_document,
        (ClassificationResult(title="Classified", confidence=88), '{"title":"Classified"}', []),
    ]
    monkeypatch.setattr(document, "_classify_document", lambda fetched_document: FakeCoroutine())
    monkeypatch.setattr(document, "run_async", lambda coroutine: run_async_results.pop(0))
    monkeypatch.setattr(
        document,
        "store_review_suggestion",
        lambda **kwargs: StoredReviewSuggestion(id=77, status="pending"),
    )
    monkeypatch.setattr(
        document,
        "mark_pipeline_run_status",
        lambda *args, **kwargs: statuses.append((args, kwargs)),
    )
    monkeypatch.setattr(
        document, "update_pipeline_run_progress", lambda *args: pipeline_progress.append(args)
    )
    monkeypatch.setattr(
        document,
        "update_actor_execution_progress",
        lambda *args, **kwargs: actor_progress.append((args, kwargs)),
    )
    monkeypatch.setattr(
        document, "finish_actor_execution", lambda *args, **kwargs: finishes.append((args, kwargs))
    )
    monkeypatch.setattr(
        document, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )

    document._handle_document_pipeline_impl(123)

    assert statuses[0] == (
        (123,),
        {
            "status": "running",
            "phase": "paperless_fetch",
            "message": "Fetching document from Paperless.",
        },
    )
    assert item_finishes == [
        ((9,), {"status": "succeeded"}),
        ((9,), {"status": "succeeded"}),
        ((9,), {"status": "succeeded"}),
    ]
    assert pipeline_progress[0][0] == 123
    assert actor_progress[0][0] == (22, pipeline_progress[0][1])
    assert events[0][0] == ("document.actor.ready",)
    assert events[1][0] == ("document.fetched",)
    assert events[2][0] == ("document.classified",)
    assert events[3][0] == ("document.review_suggestion.stored",)
    assert statuses[1][1]["status"] == "succeeded"
    assert statuses[1][1]["phase"] == "review_suggestion"
    assert finishes[0][1]["status"] == "succeeded"


def test_document_actor_schedules_retry_for_transient_failure(monkeypatch):
    retries = []
    finishes = []
    events = []

    monkeypatch.setattr(
        document,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=22, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )
    monkeypatch.setattr(
        document,
        "load_document_pipeline_run",
        lambda pipeline_run_id: DocumentPipelineRunRecord(
            id=pipeline_run_id,
            status="queued",
            paperless_document_id=42,
            paperless_modified=None,
            content_hash=None,
            retry_count=0,
            max_retries=5,
        ),
    )
    monkeypatch.setattr(
        document,
        "mark_pipeline_run_status",
        lambda *args, **kwargs: None,
    )
    monkeypatch.setattr(document, "ensure_embedding_index_ready", lambda: True)
    monkeypatch.setattr(
        document,
        "start_pipeline_item",
        lambda **kwargs: PipelineItemRecord(id=9, status="running"),
    )

    class FakeCoroutine:
        def close(self):
            return None

    monkeypatch.setattr(
        document, "_fetch_paperless_document", lambda paperless_document_id: FakeCoroutine()
    )
    monkeypatch.setattr(
        document, "run_async", lambda coroutine: (_ for _ in ()).throw(TimeoutError("slow"))
    )
    monkeypatch.setattr(
        document,
        "mark_pipeline_run_retrying",
        lambda *args, **kwargs: retries.append((args, kwargs)),
    )
    monkeypatch.setattr(
        document, "finish_actor_execution", lambda *args, **kwargs: finishes.append((args, kwargs))
    )
    monkeypatch.setattr(
        document, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )

    document._handle_document_pipeline_impl(123)

    assert retries[0] == (
        (123,),
        {
            "retry_class": "transient_network",
            "retry_reason": "TimeoutError",
            "backoff_seconds": 30,
            "phase": "document_actor",
            "message": "Document actor retry scheduled in 30 seconds.",
        },
    )
    assert finishes[-1][1]["status"] == "retrying"
    assert finishes[-1][1]["error_type"] == "transient_network"
    assert events[-1][0] == ("actor.retry_scheduled",)


def test_document_actor_fails_when_run_is_missing(monkeypatch):
    finishes = []
    events = []

    monkeypatch.setattr(
        document,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=22, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )
    monkeypatch.setattr(document, "load_document_pipeline_run", lambda pipeline_run_id: None)
    monkeypatch.setattr(
        document, "finish_actor_execution", lambda *args, **kwargs: finishes.append((args, kwargs))
    )
    monkeypatch.setattr(
        document, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )

    document._handle_document_pipeline_impl(123)

    assert finishes == [
        (
            (
                ActorExecutionHandle(
                    id=22, actor_name="handle_document_pipeline", started_monotonic=0
                ),
            ),
            {
                "status": "failed",
                "error_type": "pipeline_run_not_found",
                "error_message": "Document pipeline run was not found.",
            },
        )
    ]
    assert events[0][0] == ("actor.failed",)


def test_document_actor_rechecks_embedding_gate_before_fetch(monkeypatch):
    statuses = []
    finishes = []
    events = []
    fetches = []

    monkeypatch.setattr(
        document,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=22, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )
    monkeypatch.setattr(
        document,
        "load_document_pipeline_run",
        lambda pipeline_run_id: DocumentPipelineRunRecord(
            id=pipeline_run_id,
            status="pending",
            paperless_document_id=42,
            paperless_modified=None,
            content_hash=None,
            retry_count=0,
            max_retries=5,
        ),
    )
    monkeypatch.setattr(document, "ensure_embedding_index_ready", lambda: False)
    monkeypatch.setattr(
        document,
        "_fetch_paperless_document",
        lambda paperless_document_id: fetches.append(paperless_document_id),
    )
    monkeypatch.setattr(
        document,
        "mark_pipeline_run_status",
        lambda *args, **kwargs: statuses.append((args, kwargs)),
    )
    monkeypatch.setattr(
        document, "finish_actor_execution", lambda *args, **kwargs: finishes.append((args, kwargs))
    )
    monkeypatch.setattr(
        document, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )

    document._handle_document_pipeline_impl(123)

    assert fetches == []
    assert statuses == [
        (
            (123,),
            {
                "status": "blocked",
                "phase": "blocked",
                "message": "Waiting for embedding index to complete.",
                "error_type": "embedding_index_not_ready",
                "error": "Waiting for embedding index to complete.",
            },
        )
    ]
    assert finishes[0][1]["status"] == "blocked"
    assert finishes[0][1]["error_type"] == "embedding_index_not_ready"
    assert events[0][0] == ("pipeline.blocked.embedding_index_not_ready",)


def test_document_actor_classification_uses_pgvector_context(monkeypatch):
    captured = {}
    context_doc = SimpleNamespace(id=7, title="Context", content="Example")

    class FakePaperless:
        async def list_correspondents(self):
            return []

        async def list_document_types(self):
            return []

        async def list_storage_paths(self):
            return []

        async def list_tags(self):
            return []

        async def aclose(self):
            return None

    class FakeOllama:
        embed_model = "embed-model"

        async def embed(self, text):
            captured["embed_text"] = text
            return [0.1, 0.2]

        async def aclose(self):
            return None

    async def fake_find_similar(doc, embedding, paperless):
        captured["embedding"] = embedding
        return [SimpleNamespace(document=context_doc, distance=0.1)]

    async def fake_classify(doc, context_docs, *args):
        captured["context_docs"] = context_docs
        return ClassificationResult(title="Classified", confidence=88), "{}"

    monkeypatch.setattr(document, "PaperlessClient", FakePaperless)
    monkeypatch.setattr(document, "OllamaClient", FakeOllama)
    monkeypatch.setattr(document, "find_similar_with_precomputed_embedding", fake_find_similar)
    monkeypatch.setattr(document, "classify", fake_classify)

    result, raw, context_docs = document.run_async(
        document._classify_document(SimpleNamespace(id=42, title="Target", content="Text"))
    )

    assert result.title == "Classified"
    assert raw == "{}"
    assert captured["embed_text"] == "Target\nText"
    assert captured["embedding"] == [0.1, 0.2]
    assert captured["context_docs"] == [context_doc]
    assert context_docs == [context_doc]
