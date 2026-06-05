from types import SimpleNamespace

import pytest

from app.actors import embedding
from app.jobs.actor_execution import ActorExecutionHandle
from app.jobs.embedding_index import EmbeddingIndexBuild


def test_embedding_actor_builds_pgvector_index(monkeypatch):
    progresses = []
    actor_progresses = []
    finishes = []
    actor_finishes = []
    events = []

    monkeypatch.setattr(
        embedding,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=77, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )
    monkeypatch.setattr(
        embedding,
        "start_embedding_index_build",
        lambda **kwargs: EmbeddingIndexBuild(id=55, status="building"),
    )
    monkeypatch.setattr(
        embedding,
        "update_embedding_index_progress",
        lambda *args, **kwargs: progresses.append((args, kwargs)),
    )
    monkeypatch.setattr(
        embedding,
        "update_actor_execution_progress",
        lambda *args, **kwargs: actor_progresses.append((args, kwargs)),
    )
    monkeypatch.setattr(
        embedding,
        "finish_embedding_index_build",
        lambda *args, **kwargs: finishes.append((args, kwargs)),
    )
    monkeypatch.setattr(
        embedding,
        "finish_actor_execution",
        lambda *args, **kwargs: actor_finishes.append((args, kwargs)),
    )
    monkeypatch.setattr(
        embedding, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )

    async def fake_build(build_id, limit, actor_execution_id):
        return (2, 2, 0)

    monkeypatch.setattr(embedding, "_build_pgvector_embeddings", fake_build)

    embedding._build_initial_embedding_index_impl(limit=12)

    assert progresses == [((55,), {"document_count": 12, "embedded_count": 0, "failed_count": 0})]
    assert actor_progresses[0][0][0] == 77
    assert actor_progresses[0][1] == {"current_item": "embedding_index:55"}
    assert events[0][0] == ("embedding_index.build.started",)
    assert events[1][0] == ("embedding_index.build.completed",)
    assert finishes == [((55,), {"status": "complete", "error": None})]
    assert actor_finishes[0][1]["status"] == "succeeded"


def test_embedding_actor_schedules_retry_for_transient_build_failure(monkeypatch):
    finishes = []
    retries = []
    events = []

    monkeypatch.setattr(
        embedding,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=77, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )
    monkeypatch.setattr(
        embedding,
        "start_embedding_index_build",
        lambda **kwargs: EmbeddingIndexBuild(id=55, status="building"),
    )
    monkeypatch.setattr(embedding, "update_embedding_index_progress", lambda *args, **kwargs: None)
    monkeypatch.setattr(embedding, "update_actor_execution_progress", lambda *args, **kwargs: None)
    monkeypatch.setattr(
        embedding,
        "finish_embedding_index_build",
        lambda *args, **kwargs: finishes.append((args, kwargs)),
    )
    monkeypatch.setattr(
        embedding,
        "schedule_actor_execution_retry",
        lambda *args, **kwargs: retries.append((args, kwargs)),
    )
    monkeypatch.setattr(
        embedding, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )

    async def fake_build(build_id, limit, actor_execution_id):
        raise ConnectionError("ollama unavailable")

    monkeypatch.setattr(embedding, "_build_pgvector_embeddings", fake_build)

    with pytest.raises(ConnectionError):
        embedding._build_initial_embedding_index_impl(limit=12)

    assert finishes == [
        (
            (55,),
            {
                "status": "failed",
                "error": "Retry scheduled after ConnectionError: ollama unavailable",
            },
        )
    ]
    assert retries[0][1] == {
        "retry_class": "transient_network",
        "retry_reason": "ConnectionError",
        "backoff_seconds": 30,
        "error_message": "ollama unavailable",
    }
    assert events[-1][0] == ("actor.retry_scheduled",)
    assert events[-1][1]["payload"]["embedding_index_state_id"] == 55


@pytest.mark.asyncio
async def test_build_pgvector_embeddings_embeds_and_stores_documents(monkeypatch):
    progresses = []
    actor_progresses = []
    stored = []

    class FakePaperless:
        async def list_all_documents(self, limit=None):
            return [
                SimpleNamespace(
                    id=1,
                    title="Doc 1",
                    content="Content 1",
                    created_date="2026-05-08",
                    correspondent=10,
                    document_type=20,
                    storage_path=30,
                    tags=[1, 2],
                    modified="2026-05-08T12:00:00Z",
                ),
                SimpleNamespace(
                    id=2,
                    title="Doc 2",
                    content="Content 2",
                    created_date=None,
                    correspondent=None,
                    document_type=None,
                    storage_path=None,
                    tags=[],
                    modified=None,
                ),
                SimpleNamespace(
                    id=3,
                    title="Inbox",
                    content="Do not trust",
                    created_date=None,
                    correspondent=None,
                    document_type=None,
                    storage_path=None,
                    tags=[99],
                    modified=None,
                ),
                SimpleNamespace(
                    id=4,
                    title="",
                    content="",
                    created_date=None,
                    correspondent=None,
                    document_type=None,
                    storage_path=None,
                    tags=[],
                    modified=None,
                ),
            ]

        async def aclose(self):
            return None

    class FakeOllama:
        embed_model = "embed-model"

        async def embed(self, text):
            return [0.1, 0.2]

        async def aclose(self):
            return None

    monkeypatch.setattr(embedding, "PaperlessClient", FakePaperless)
    monkeypatch.setattr(embedding, "create_ai_provider", FakeOllama)
    monkeypatch.setattr(
        embedding,
        "update_embedding_index_progress",
        lambda *args, **kwargs: progresses.append((args, kwargs)),
    )
    monkeypatch.setattr(
        embedding,
        "update_actor_execution_progress",
        lambda *args, **kwargs: actor_progresses.append((args, kwargs)),
    )
    monkeypatch.setattr(embedding, "store_document_embedding", lambda item: stored.append(item))

    monkeypatch.setattr(embedding.settings, "paperless_inbox_tag_id", 99)

    assert await embedding._build_pgvector_embeddings(55, None, 77) == (2, 2, 0)
    assert [item.paperless_document_id for item in stored] == [1, 2]
    assert all(item.trusted_for_context for item in stored)
    assert progresses[-1] == ((55,), {"document_count": 2, "embedded_count": 2, "failed_count": 0})
    assert len(actor_progresses) == 2


@pytest.mark.asyncio
async def test_build_pgvector_embeddings_does_not_abort_on_document_text_type_error(monkeypatch):
    progresses = []
    stored = []

    class FakePaperless:
        async def list_all_documents(self, limit=None):
            return [
                SimpleNamespace(
                    id=1,
                    title="Good",
                    content="Content",
                    created_date=None,
                    correspondent=None,
                    document_type=None,
                    storage_path=None,
                    tags=[],
                    modified=None,
                ),
                SimpleNamespace(
                    id=2,
                    title="Bad",
                    content="Content",
                    created_date=None,
                    correspondent=None,
                    document_type=None,
                    storage_path=None,
                    tags=[],
                    modified=None,
                ),
            ]

        async def aclose(self):
            return None

    class FakeOllama:
        embed_model = "embed-model"

        async def embed(self, text):
            return [0.1, 0.2]

        async def aclose(self):
            return None

    def fake_document_embedding_text(title, content):
        if title == "Bad":
            raise TypeError("'<' not supported between instances of 'str' and 'int'")
        return f"{title}\n{content}"

    monkeypatch.setattr(embedding, "PaperlessClient", FakePaperless)
    monkeypatch.setattr(embedding, "create_ai_provider", FakeOllama)
    monkeypatch.setattr(embedding, "document_embedding_text", fake_document_embedding_text)
    monkeypatch.setattr(
        embedding,
        "update_embedding_index_progress",
        lambda *args, **kwargs: progresses.append((args, kwargs)),
    )
    monkeypatch.setattr(embedding, "store_document_embedding", lambda item: stored.append(item))

    assert await embedding._build_pgvector_embeddings(55, None, None) == (2, 1, 1)
    assert [item.paperless_document_id for item in stored] == [1]
    assert progresses[-1] == ((55,), {"document_count": 2, "embedded_count": 1, "failed_count": 1})


def test_embedding_actor_skips_when_build_already_running(monkeypatch):
    actor_finishes = []
    events = []
    build_calls = []

    monkeypatch.setattr(
        embedding,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=77, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )
    monkeypatch.setattr(
        embedding,
        "start_embedding_index_build",
        lambda **kwargs: EmbeddingIndexBuild(id=55, status="building", already_running=True),
    )
    monkeypatch.setattr(
        embedding,
        "finish_actor_execution",
        lambda *args, **kwargs: actor_finishes.append((args, kwargs)),
    )
    monkeypatch.setattr(
        embedding, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )

    async def fake_build(build_id, limit, actor_execution_id):
        build_calls.append(build_id)
        return (0, 0, 0)

    monkeypatch.setattr(embedding, "_build_pgvector_embeddings", fake_build)

    embedding._build_initial_embedding_index_impl(limit=12)

    assert build_calls == []
    assert actor_finishes[0][1]["status"] == "skipped"
    assert actor_finishes[0][1]["error_type"] == "embedding_index_already_building"
    assert events[0][1]["payload"]["already_running"] is True
