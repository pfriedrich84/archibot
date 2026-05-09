from app.actors import webhook
from app.jobs.actor_execution import ActorExecutionHandle
from app.jobs.pipeline_start import PipelineStartResult
from app.jobs.webhook_delivery import WebhookDeliveryRecord


def test_webhook_reprocess_policy_marks_changed_events_only():
    assert webhook.webhook_requests_reprocess("document.updated") is True
    assert webhook.webhook_requests_reprocess("document_changed") is True
    assert webhook.webhook_requests_reprocess("document.created") is False
    assert webhook.webhook_requests_reprocess("document.deleted") is False


def test_webhook_actor_starts_shared_pipeline_and_marks_blocked(monkeypatch):
    events = []
    statuses = []
    starts = []
    actor_finishes = []

    monkeypatch.setattr(
        webhook,
        "load_webhook_delivery",
        lambda webhook_delivery_id: WebhookDeliveryRecord(
            id=webhook_delivery_id,
            event_type="document.created",
            paperless_document_id=42,
            paperless_modified="2026-05-08T12:00:00Z",
            status="queued",
            normalized_payload={},
        ),
    )
    monkeypatch.setattr(
        webhook,
        "publish_pipeline_event",
        lambda *args, **kwargs: events.append((args, kwargs)),
    )

    def fake_start(**kwargs):
        starts.append(kwargs)
        return PipelineStartResult(
            status="blocked",
            pipeline_run_id=None,
            pipeline_dedupe_key="dedupe",
            blocked_reason="embedding_index_not_ready",
        )

    monkeypatch.setattr(
        webhook,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=99, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )
    monkeypatch.setattr(
        webhook,
        "finish_actor_execution",
        lambda *args, **kwargs: actor_finishes.append((args, kwargs)),
    )
    monkeypatch.setattr(webhook, "start_or_attach_document_pipeline", fake_start)
    monkeypatch.setattr(
        webhook,
        "mark_webhook_delivery_status",
        lambda *args: statuses.append(args),
    )

    webhook._handle_paperless_webhook_impl(123)

    assert starts == [
        {
            "trigger_source": "webhook",
            "paperless_document_id": 42,
            "paperless_modified": "2026-05-08T12:00:00Z",
            "reprocess_requested": False,
            "reprocess_reason": None,
            "reprocess_mode": None,
        }
    ]
    assert statuses == [(123, "blocked", "embedding_index_not_ready")]
    assert events[0][0] == ("webhook.normalized",)
    assert events[0][1]["webhook_delivery_id"] == 123
    assert events[0][1]["paperless_document_id"] == 42
    assert actor_finishes[0][1] == {"status": "blocked", "error_type": "embedding_index_not_ready"}


def test_webhook_actor_marks_processed_when_pipeline_start_is_accepted(monkeypatch):
    statuses = []
    actor_finishes = []

    monkeypatch.setattr(
        webhook,
        "load_webhook_delivery",
        lambda webhook_delivery_id: WebhookDeliveryRecord(
            id=webhook_delivery_id,
            event_type="document.updated",
            paperless_document_id=7,
            paperless_modified=None,
            status="queued",
            normalized_payload={},
        ),
    )
    monkeypatch.setattr(webhook, "publish_pipeline_event", lambda *args, **kwargs: None)
    monkeypatch.setattr(
        webhook,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=100, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )
    monkeypatch.setattr(
        webhook,
        "finish_actor_execution",
        lambda *args, **kwargs: actor_finishes.append((args, kwargs)),
    )
    starts = []

    def fake_start(**kwargs):
        starts.append(kwargs)
        return PipelineStartResult(
            status="pending",
            pipeline_run_id=None,
            pipeline_dedupe_key="dedupe",
        )

    monkeypatch.setattr(webhook, "start_or_attach_document_pipeline", fake_start)
    monkeypatch.setattr(
        webhook,
        "mark_webhook_delivery_status",
        lambda *args: statuses.append(args),
    )

    webhook._handle_paperless_webhook_impl(321)

    assert statuses == [(321, "processed", None)]
    assert starts[0]["reprocess_requested"] is True
    assert starts[0]["reprocess_reason"] == "document.updated"
    assert starts[0]["reprocess_mode"] == "webhook"
    assert actor_finishes[0][1] == {"status": "succeeded", "error_type": None}


def test_webhook_actor_emits_failure_event_for_missing_delivery(monkeypatch):
    events = []

    monkeypatch.setattr(webhook, "load_webhook_delivery", lambda webhook_delivery_id: None)
    monkeypatch.setattr(
        webhook,
        "publish_pipeline_event",
        lambda *args, **kwargs: events.append((args, kwargs)),
    )

    webhook._handle_paperless_webhook_impl(404)

    assert events == [
        (
            ("actor.failed",),
            {
                "webhook_delivery_id": 404,
                "level": "error",
                "message": "Webhook delivery was not found for actor execution.",
                "payload": {"actor_name": "handle_paperless_webhook"},
            },
        )
    ]
