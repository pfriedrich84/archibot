from types import SimpleNamespace

from app.actors import document
from app.jobs.actor_execution import ActorExecutionHandle
from app.jobs.pipeline_items import PipelineItemRecord
from app.jobs.pipeline_runs import DocumentPipelineRunRecord
from app.jobs.review_suggestions import StoredReviewSuggestion
from app.models import ClassificationResult, PaperlessDocument


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
    monkeypatch.setattr(document, "is_pipeline_run_cancel_requested", lambda pipeline_run_id: False)
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
    outcome = document.DocumentClassificationOutcome(
        document=fetched_document,
        result=ClassificationResult(title="Classified", confidence=88),
        raw_response='{"title":"Classified"}',
        context_documents=[],
        catalog=document.EntityCatalog(correspondents=[], doctypes=[], storage_paths=[], tags=[]),
    )
    run_async_results = [fetched_document, outcome]
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
        ((9,), {"status": "skipped", "error": None}),
        ((9,), {"status": "succeeded", "error": None}),
        ((9,), {"status": "skipped", "error": None}),
        ((9,), {"status": "succeeded"}),
    ]
    assert pipeline_progress[0][0] == 123
    assert actor_progress[0][0] == (22, pipeline_progress[0][1])
    assert events[0][0] == ("document.actor.ready",)
    assert events[1][0] == ("document.fetched",)
    assert events[2][0] == ("document.ocr.skipped",)
    assert events[3][0] == ("document.context.searched",)
    assert events[4][0] == ("document.classified",)
    assert events[5][0] == ("document.judge.completed",)
    assert events[6][0] == ("document.review_suggestion.stored",)
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
    monkeypatch.setattr(document, "is_pipeline_run_cancel_requested", lambda pipeline_run_id: False)
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


def test_document_actor_cancellation_during_external_failure_suppresses_retry(monkeypatch):
    retries = []
    finishes = []
    cancelled = []
    items = []
    cancellation_checks = 0

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
    monkeypatch.setattr(document, "mark_pipeline_run_status", lambda *args, **kwargs: None)
    monkeypatch.setattr(document, "ensure_embedding_index_ready", lambda: True)

    def cancellation_requested(pipeline_run_id):
        nonlocal cancellation_checks
        cancellation_checks += 1
        return cancellation_checks >= 3

    monkeypatch.setattr(document, "is_pipeline_run_cancel_requested", cancellation_requested)
    monkeypatch.setattr(
        document,
        "start_pipeline_item",
        lambda **kwargs: PipelineItemRecord(id=9, status="running"),
    )
    monkeypatch.setattr(
        document,
        "finish_pipeline_item",
        lambda *args, **kwargs: items.append((args, kwargs)),
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
        "mark_pipeline_run_cancelled",
        lambda pipeline_run_id: cancelled.append(pipeline_run_id),
    )
    monkeypatch.setattr(
        document,
        "mark_pipeline_run_retrying",
        lambda *args, **kwargs: retries.append((args, kwargs)),
    )
    monkeypatch.setattr(
        document, "finish_actor_execution", lambda *args, **kwargs: finishes.append((args, kwargs))
    )
    monkeypatch.setattr(document, "publish_pipeline_event", lambda *args, **kwargs: None)

    document._handle_document_pipeline_impl(123)

    assert cancelled == [123]
    assert retries == []
    assert items[-1][1]["status"] == "skipped"
    assert finishes[-1][1]["status"] == "cancelled"


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
    monkeypatch.setattr(document, "is_pipeline_run_cancel_requested", lambda pipeline_run_id: False)
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

    monkeypatch.setattr(document, "find_similar_with_precomputed_embedding", fake_find_similar)
    monkeypatch.setattr(document, "classify", fake_classify)

    outcome = document.run_async(
        document._classify_document(
            SimpleNamespace(id=42, title="Target", content="Text"),
            paperless=FakePaperless(),
            ai_provider=FakeOllama(),
        )
    )

    assert outcome.result.title == "Classified"
    assert outcome.raw_response == "{}"
    assert captured["embed_text"] == "Target\nText"
    assert captured["embedding"] == [0.1, 0.2]
    assert captured["context_docs"] == [context_doc]
    assert outcome.context_documents == [context_doc]


def test_document_actor_auto_commit_creates_durable_commit_command(monkeypatch):
    events = []
    commands = []
    accepted = []

    monkeypatch.setattr(document.settings, "auto_commit_confidence", 80)
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
    monkeypatch.setattr(document, "is_pipeline_run_cancel_requested", lambda pipeline_run_id: False)
    monkeypatch.setattr(
        document,
        "start_pipeline_item",
        lambda **kwargs: PipelineItemRecord(id=len(accepted) + len(events) + 1, status="running"),
    )
    monkeypatch.setattr(document, "finish_pipeline_item", lambda *args, **kwargs: None)
    monkeypatch.setattr(
        document, "progress_from_pipeline_items", lambda pipeline_run_id: (4, 4, 0, 0)
    )
    monkeypatch.setattr(document, "update_pipeline_run_progress", lambda *args: None)
    monkeypatch.setattr(document, "update_actor_execution_progress", lambda *args, **kwargs: None)
    monkeypatch.setattr(document, "mark_pipeline_run_status", lambda *args, **kwargs: None)
    monkeypatch.setattr(document, "finish_actor_execution", lambda *args, **kwargs: None)
    monkeypatch.setattr(
        document, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )

    fetched_document = SimpleNamespace(title="Doc", content="Text")
    outcome = document.DocumentClassificationOutcome(
        document=fetched_document,
        result=ClassificationResult(title="Classified", confidence=88),
        raw_response='{"title":"Classified"}',
        context_documents=[],
        catalog=document.EntityCatalog(correspondents=[], doctypes=[], storage_paths=[], tags=[]),
    )
    run_async_results = [fetched_document, outcome]

    class FakeCoroutine:
        def close(self):
            return None

    monkeypatch.setattr(
        document, "_fetch_paperless_document", lambda paperless_document_id: FakeCoroutine()
    )
    monkeypatch.setattr(document, "_classify_document", lambda fetched_document: FakeCoroutine())
    monkeypatch.setattr(document, "run_async", lambda coroutine: run_async_results.pop(0))
    monkeypatch.setattr(
        document,
        "store_review_suggestion",
        lambda **kwargs: StoredReviewSuggestion(id=77, status="pending"),
    )
    monkeypatch.setattr(
        document,
        "mark_review_suggestion_auto_accepted",
        lambda suggestion_id, **kwargs: accepted.append((suggestion_id, kwargs)) or (True, []),
    )
    monkeypatch.setattr(
        document,
        "ensure_review_commit_command",
        lambda suggestion_id: commands.append(suggestion_id) or 91,
    )

    document._handle_document_pipeline_impl(123)

    assert accepted == [(77, {"reason": "auto_commit_confidence", "confidence": 88})]
    assert commands == [77]
    assert any(event[0][0] == "document.auto_commit.requested" for event in events)


def test_document_actor_auto_commit_skips_unresolved_entities(monkeypatch):
    events = []
    commands = []

    monkeypatch.setattr(document.settings, "auto_commit_confidence", 80)
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
    monkeypatch.setattr(document, "is_pipeline_run_cancel_requested", lambda pipeline_run_id: False)
    monkeypatch.setattr(
        document, "start_pipeline_item", lambda **kwargs: PipelineItemRecord(id=9, status="running")
    )
    monkeypatch.setattr(document, "finish_pipeline_item", lambda *args, **kwargs: None)
    monkeypatch.setattr(
        document, "progress_from_pipeline_items", lambda pipeline_run_id: (4, 3, 0, 1)
    )
    monkeypatch.setattr(document, "update_pipeline_run_progress", lambda *args: None)
    monkeypatch.setattr(document, "update_actor_execution_progress", lambda *args, **kwargs: None)
    monkeypatch.setattr(document, "mark_pipeline_run_status", lambda *args, **kwargs: None)
    monkeypatch.setattr(document, "finish_actor_execution", lambda *args, **kwargs: None)
    monkeypatch.setattr(
        document, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )

    fetched_document = SimpleNamespace(title="Doc", content="Text")
    outcome = document.DocumentClassificationOutcome(
        document=fetched_document,
        result=ClassificationResult(title="Classified", confidence=88, correspondent="New"),
        raw_response='{"title":"Classified"}',
        context_documents=[],
        catalog=document.EntityCatalog(correspondents=[], doctypes=[], storage_paths=[], tags=[]),
    )
    run_async_results = [fetched_document, outcome]

    class FakeCoroutine:
        def close(self):
            return None

    monkeypatch.setattr(
        document, "_fetch_paperless_document", lambda paperless_document_id: FakeCoroutine()
    )
    monkeypatch.setattr(document, "_classify_document", lambda fetched_document: FakeCoroutine())
    monkeypatch.setattr(document, "run_async", lambda coroutine: run_async_results.pop(0))
    monkeypatch.setattr(
        document,
        "store_review_suggestion",
        lambda **kwargs: StoredReviewSuggestion(id=77, status="pending"),
    )
    monkeypatch.setattr(
        document,
        "mark_review_suggestion_auto_accepted",
        lambda suggestion_id, **kwargs: (False, ["correspondent"]),
    )
    monkeypatch.setattr(
        document,
        "ensure_review_commit_command",
        lambda suggestion_id: commands.append(suggestion_id) or 91,
    )

    document._handle_document_pipeline_impl(123)

    assert commands == []
    assert any(event[0][0] == "document.auto_commit.skipped" for event in events)


def test_classify_document_applies_ocr_locally(monkeypatch):
    captured = {}

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
        async def embed(self, text):
            captured["embed_text"] = text
            return [0.1, 0.2]

        async def aclose(self):
            return None

    async def fake_find_similar(doc, embedding, paperless):
        return []

    async def fake_classify(doc, context_docs, *args):
        captured["classified_content"] = doc.content
        return ClassificationResult(title="Classified", confidence=88), "{}"

    async def fake_maybe_correct(doc, ollama, paperless):
        return "Corrected OCR", 2

    monkeypatch.setattr(document, "effective_ocr_mode", lambda: "text")
    monkeypatch.setattr(
        document, "should_run_ocr_for_document", lambda *args, **kwargs: (True, "no_filter")
    )
    monkeypatch.setattr(document, "maybe_correct_ocr", fake_maybe_correct)
    monkeypatch.setattr(
        document,
        "cache_ocr_correction",
        lambda *args, **kwargs: captured.setdefault("cached", args),
    )
    monkeypatch.setattr(document, "find_similar_with_precomputed_embedding", fake_find_similar)
    monkeypatch.setattr(document, "classify", fake_classify)

    async def fake_judge(*args, **kwargs):
        return SimpleNamespace(
            result=args[1], verdict="skipped", reasoning=None, original_proposed_json=None
        )

    monkeypatch.setattr(document, "maybe_run_judge", fake_judge)

    outcome = document.run_async(
        document._classify_document(
            PaperlessDocument(id=42, title="Target", content="Broken OCR", tags=[]),
            paperless=FakePaperless(),
            ai_provider=FakeOllama(),
        )
    )

    assert outcome.ocr_corrected is True
    assert outcome.ocr_corrections == 2
    assert captured["classified_content"] == "Corrected OCR"
    assert captured["cached"] == (42, "Corrected OCR", "text", 2)
