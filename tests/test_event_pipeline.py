from app.events import types
from app.jobs.idempotency import pipeline_dedupe_key, webhook_dedupe_key
from app.jobs.pipeline_runs import PipelineRunRecord
from app.jobs.pipeline_start import force_pipeline_dedupe_key, start_or_attach_document_pipeline


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
    assert result.outcome == "blocked"
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
                    "force_new_run": False,
                    "outcome": "blocked",
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
    assert result.outcome == "created"
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
                    "force_new_run": False,
                    "outcome": "created",
                },
            },
        )
    ]


def test_pipeline_start_reports_coalesced_when_run_already_exists(monkeypatch):
    events = []

    monkeypatch.setattr("app.jobs.pipeline_start.ensure_embedding_index_ready", lambda: True)
    monkeypatch.setattr(
        "app.jobs.pipeline_start.publish_pipeline_event",
        lambda *args, **kwargs: events.append((args, kwargs)),
    )
    monkeypatch.setattr(
        "app.jobs.pipeline_start.upsert_document_pipeline_run",
        lambda **kwargs: PipelineRunRecord(id=456, status="pending", created=False),
    )

    result = start_or_attach_document_pipeline(
        trigger_source="webhook",
        paperless_document_id=7,
        paperless_modified="2026-05-08T12:00:00Z",
    )

    assert result.status == "pending"
    assert result.pipeline_run_id == 456
    assert result.created is False
    assert result.outcome == "coalesced"
    assert events[0][0] == (types.PIPELINE_START_COALESCED,)
    assert events[0][1]["payload"]["outcome"] == "coalesced"


def test_pipeline_start_force_new_uses_force_token_and_reports_force_created(monkeypatch):
    events = []
    calls = []

    monkeypatch.setattr("app.jobs.pipeline_start.ensure_embedding_index_ready", lambda: True)
    monkeypatch.setattr(
        "app.jobs.pipeline_start.publish_pipeline_event",
        lambda *args, **kwargs: events.append((args, kwargs)),
    )

    def fake_upsert(**kwargs):
        calls.append(kwargs)
        return PipelineRunRecord(id=999, status="pending", created=True)

    monkeypatch.setattr("app.jobs.pipeline_start.upsert_document_pipeline_run", fake_upsert)

    result = start_or_attach_document_pipeline(
        trigger_source="manual",
        paperless_document_id=42,
        paperless_modified="2026-05-08T12:00:00Z",
        content_hash="hash",
        force_new_run=True,
        force_token="token-1",
        reprocess_requested=True,
        reprocess_mode="manual",
        requested_by_user_id=5,
    )

    assert result.outcome == "force_created"
    assert result.pipeline_dedupe_key == force_pipeline_dedupe_key(
        paperless_document_id=42,
        paperless_modified="2026-05-08T12:00:00Z",
        content_hash="hash",
        force_token="token-1",
    )
    assert calls[0]["pipeline_dedupe_key"] == result.pipeline_dedupe_key
    assert calls[0]["requested_by_user_id"] == 5
    assert events[0][0] == (types.PIPELINE_START_PENDING,)
    assert events[0][1]["payload"]["outcome"] == "force_created"
    assert events[1][0] == (types.PIPELINE_FORCE_REPROCESS_REQUESTED,)


def test_pipeline_start_same_content_coalesces_and_changed_modified_changes_dedupe(monkeypatch):
    created_keys = set()

    monkeypatch.setattr("app.jobs.pipeline_start.ensure_embedding_index_ready", lambda: True)
    monkeypatch.setattr(
        "app.jobs.pipeline_start.publish_pipeline_event", lambda *args, **kwargs: None
    )

    def fake_upsert(**kwargs):
        created = kwargs["pipeline_dedupe_key"] not in created_keys
        created_keys.add(kwargs["pipeline_dedupe_key"])
        return PipelineRunRecord(id=len(created_keys), status="pending", created=created)

    monkeypatch.setattr("app.jobs.pipeline_start.upsert_document_pipeline_run", fake_upsert)

    first = start_or_attach_document_pipeline(
        trigger_source="webhook",
        paperless_document_id=42,
        paperless_modified="2026-05-08T12:00:00Z",
    )
    second = start_or_attach_document_pipeline(
        trigger_source="poll",
        paperless_document_id=42,
        paperless_modified="2026-05-08T12:00:00Z",
    )
    changed = start_or_attach_document_pipeline(
        trigger_source="poll",
        paperless_document_id=42,
        paperless_modified="2026-05-09T12:00:00Z",
    )

    assert first.outcome == "created"
    assert second.outcome == "coalesced"
    assert changed.outcome == "created"
    assert first.pipeline_dedupe_key == second.pipeline_dedupe_key
    assert changed.pipeline_dedupe_key != first.pipeline_dedupe_key
