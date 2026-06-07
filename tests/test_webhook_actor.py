from app.actors import webhook
from app.jobs.actor_execution import ActorExecutionHandle
from app.jobs.pipeline_start import PipelineStartResult
from app.jobs.webhook_delivery import WebhookDeliveryRecord


def _capture_progress(monkeypatch):
    progresses = []
    monkeypatch.setattr(
        webhook,
        "update_actor_execution_progress",
        lambda *args, **kwargs: progresses.append((args, kwargs)),
    )
    return progresses


def test_webhook_action_validation_accepts_only_laravel_normalized_actions():
    assert webhook.validated_webhook_action("refresh_embedding") == "refresh_embedding"
    assert webhook.validated_webhook_action("process_document") == "process_document"
    assert webhook.validated_webhook_action("delete_embedding") == "delete_embedding"
    assert webhook.webhook_requests_reprocess("process_document") is False

    for action in [None, "document.updated", "unknown"]:
        try:
            webhook.validated_webhook_action(action)
        except webhook.InvalidWebhookAction:
            pass
        else:  # pragma: no cover - failure path
            raise AssertionError(f"accepted invalid webhook action: {action}")


def test_webhook_actor_starts_shared_pipeline_and_marks_blocked(monkeypatch):
    events = []
    statuses = []
    starts = []
    actor_finishes = []
    progresses = _capture_progress(monkeypatch)

    monkeypatch.setattr(
        webhook,
        "load_webhook_delivery",
        lambda webhook_delivery_id: WebhookDeliveryRecord(
            id=webhook_delivery_id,
            event_type="document.created",
            webhook_action="process_document",
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
            "webhook_delivery_id": 123,
        }
    ]
    assert statuses == [(123, "blocked", "embedding_index_not_ready")]
    assert events[0][0] == ("webhook.normalized",)
    assert events[0][1]["webhook_delivery_id"] == 123
    assert events[0][1]["paperless_document_id"] == 42
    assert actor_finishes[0][1] == {"status": "blocked", "error_type": "embedding_index_not_ready"}
    assert [call[0][1].phase for call in progresses] == [
        "webhook_normalize",
        "process_document",
        "webhook_finished",
    ]


def test_webhook_actor_refreshes_embedding_for_updated_events(monkeypatch):
    statuses = []
    actor_finishes = []
    starts = []
    refreshes = []
    progresses = _capture_progress(monkeypatch)

    monkeypatch.setattr(
        webhook,
        "load_webhook_delivery",
        lambda webhook_delivery_id: WebhookDeliveryRecord(
            id=webhook_delivery_id,
            event_type="document.updated",
            webhook_action="refresh_embedding",
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
    monkeypatch.setattr(
        webhook,
        "start_or_attach_document_pipeline",
        lambda **kwargs: starts.append(kwargs),
    )
    monkeypatch.setattr(
        webhook,
        "refresh_document_embedding",
        lambda paperless_document_id: (
            refreshes.append(paperless_document_id)
            or webhook.EmbeddingRefreshResult(status="processed")
        ),
    )
    monkeypatch.setattr(
        webhook,
        "mark_webhook_delivery_status",
        lambda *args: statuses.append(args),
    )

    webhook._handle_paperless_webhook_impl(321)

    assert statuses == [(321, "processed", None)]
    assert starts == []
    assert refreshes == [7]
    assert actor_finishes[0][1] == {"status": "succeeded", "error_type": None}
    assert [call[0][1].phase for call in progresses] == [
        "webhook_normalize",
        "refresh_embedding",
        "webhook_finished",
    ]


def test_webhook_actor_marks_invalid_persisted_action_failed_permanent(monkeypatch):
    events = []
    statuses = []
    actor_finishes = []
    _capture_progress(monkeypatch)

    monkeypatch.setattr(
        webhook,
        "load_webhook_delivery",
        lambda webhook_delivery_id: WebhookDeliveryRecord(
            id=webhook_delivery_id,
            event_type="document.updated",
            webhook_action=None,
            paperless_document_id=7,
            paperless_modified=None,
            status="queued",
            normalized_payload={},
        ),
    )
    monkeypatch.setattr(
        webhook,
        "publish_pipeline_event",
        lambda *args, **kwargs: events.append((args, kwargs)),
    )
    monkeypatch.setattr(
        webhook,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=101, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )
    monkeypatch.setattr(
        webhook,
        "finish_actor_execution",
        lambda *args, **kwargs: actor_finishes.append((args, kwargs)),
    )
    monkeypatch.setattr(
        webhook,
        "mark_webhook_delivery_status",
        lambda *args: statuses.append(args),
    )

    webhook._handle_paperless_webhook_impl(654)

    assert statuses == [(654, "failed_permanent", "invalid_webhook_action")]
    assert events == [
        (
            ("webhook.invalid_action",),
            {
                "webhook_delivery_id": 654,
                "paperless_document_id": 7,
                "level": "error",
                "message": "Webhook delivery has missing or invalid Laravel-normalized action metadata.",
                "payload": {
                    "event_type": "document.updated",
                    "webhook_action": None,
                    "valid_webhook_actions": [
                        "delete_embedding",
                        "process_document",
                        "refresh_embedding",
                    ],
                },
            },
        )
    ]
    assert actor_finishes[0][1]["status"] == "failed"
    assert actor_finishes[0][1]["error_type"] == "invalid_webhook_action"


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
