from app.events import types
from app.jobs.idempotency import pipeline_dedupe_key, webhook_dedupe_key
from app.jobs.pipeline_runs import PipelineRunRecord
from app.jobs.pipeline_start import start_or_attach_document_pipeline


def test_webhook_dedupe_key_includes_unknown_modified_marker():
    assert (
        webhook_dedupe_key(
            source="paperless",
            event_type="document.created",
            paperless_document_id=42,
            paperless_modified=None,
            payload_hash="abc123",
        )
        == "paperless:document.created:42:unknown_modified:abc123"
    )


def test_pipeline_dedupe_key_is_stable_and_changes_with_content_hash():
    base = pipeline_dedupe_key(
        paperless_document_id=42,
        paperless_modified="2026-05-08T12:00:00Z",
        content_hash=None,
    )
    same = pipeline_dedupe_key(
        paperless_document_id=42,
        paperless_modified="2026-05-08T12:00:00Z",
        content_hash=None,
    )
    changed = pipeline_dedupe_key(
        paperless_document_id=42,
        paperless_modified="2026-05-08T12:00:00Z",
        content_hash="sha256:content",
    )

    assert base == same
    assert base != changed
    assert len(base) == 64


def test_pipeline_start_fails_closed_and_publishes_blocked_event(monkeypatch):
    events = []

    monkeypatch.setattr("app.jobs.pipeline_start.ensure_embedding_index_ready", lambda: False)
    monkeypatch.setattr(
        "app.jobs.pipeline_start.publish_pipeline_event",
        lambda *args, **kwargs: events.append((args, kwargs)),
    )
    monkeypatch.setattr(
        "app.jobs.pipeline_start.upsert_document_pipeline_run",
        lambda **kwargs: PipelineRunRecord(id=123, status=kwargs["status"]),
    )

    result = start_or_attach_document_pipeline(
        trigger_source="webhook",
        paperless_document_id=42,
        paperless_modified="2026-05-08T12:00:00Z",
    )

    assert result.status == "blocked"
    assert result.pipeline_run_id == 123
    assert result.blocked_reason == "embedding_index_not_ready"
    assert events == [
        (
            (types.PIPELINE_BLOCKED_EMBEDDING_NOT_READY,),
            {
                "pipeline_run_id": 123,
                "paperless_document_id": 42,
                "level": "warning",
                "message": "Document pipeline start blocked because the embedding index is not ready.",
                "payload": {
                    "trigger_source": "webhook",
                    "pipeline_dedupe_key": result.pipeline_dedupe_key,
                    "paperless_modified": "2026-05-08T12:00:00Z",
                    "content_hash_present": False,
                },
            },
        )
    ]


def test_pipeline_start_accepts_when_embedding_index_is_ready(monkeypatch):
    events = []

    monkeypatch.setattr("app.jobs.pipeline_start.ensure_embedding_index_ready", lambda: True)
    monkeypatch.setattr(
        "app.jobs.pipeline_start.publish_pipeline_event",
        lambda *args, **kwargs: events.append((args, kwargs)),
    )
    monkeypatch.setattr(
        "app.jobs.pipeline_start.upsert_document_pipeline_run",
        lambda **kwargs: PipelineRunRecord(id=456, status=kwargs["status"]),
    )

    result = start_or_attach_document_pipeline(
        trigger_source="poll",
        paperless_document_id=7,
        paperless_modified=None,
        content_hash="hash",
    )

    assert result.status == "pending"
    assert result.pipeline_run_id == 456
    assert result.blocked_reason is None
    assert events == [
        (
            (types.PIPELINE_START_PENDING,),
            {
                "pipeline_run_id": 456,
                "paperless_document_id": 7,
                "message": "Document pipeline start accepted by the shared trigger gate.",
                "payload": {
                    "trigger_source": "poll",
                    "pipeline_dedupe_key": result.pipeline_dedupe_key,
                    "paperless_modified": None,
                    "content_hash_present": True,
                },
            },
        )
    ]
