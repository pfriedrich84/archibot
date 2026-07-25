from app.actors import document_batch
from app.jobs.actor_execution import ActorExecutionHandle
from app.jobs.pipeline_runs import DocumentPipelineRunRecord


def test_recovered_batch_reconciles_completed_children_without_reprocessing(monkeypatch):
    finishes = []
    events = []
    fences = []

    monkeypatch.setattr(
        document_batch,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=91,
            actor_name=kwargs["actor_name"],
            started_monotonic=0,
        ),
    )
    monkeypatch.setattr(
        document_batch,
        "list_document_pipeline_runs_for_command",
        lambda source_command_id, batch_command_id: [
            DocumentPipelineRunRecord(
                id=7,
                status="succeeded",
                paperless_document_id=42,
                paperless_modified=None,
                content_hash=None,
                retry_count=0,
                max_retries=5,
                command_id=source_command_id,
                batch_command_id=batch_command_id,
            )
        ],
    )
    monkeypatch.setattr(
        document_batch,
        "ensure_staged_batch_active",
        lambda command_id, run_ids: fences.append((command_id, run_ids)),
    )
    monkeypatch.setattr(
        document_batch,
        "finish_actor_execution",
        lambda *args, **kwargs: finishes.append((args, kwargs)),
    )
    monkeypatch.setattr(
        document_batch,
        "publish_pipeline_event",
        lambda *args, **kwargs: events.append((args, kwargs)),
    )
    monkeypatch.setattr(
        document_batch,
        "_fetch_paperless_document",
        lambda document_id: (_ for _ in ()).throw(AssertionError("must not reprocess")),
    )

    document_batch._handle_staged_document_batch_impl(
        command_id=55,
        source_command_id=12,
        embedding_ready=True,
    )

    assert fences == [(55, [7])]
    assert finishes[-1][1] == {"status": "succeeded"}
    assert events == [
        (
            ("pipeline.batch.completed",),
            {
                "command_id": 55,
                "message": "Globally staged document batch completion reconciled after recovery.",
                "payload": {
                    "source_command_id": 12,
                    "document_count": 1,
                    "phase_order": ["embedding", "ocr", "classification", "judge"],
                    "reconciled_after_recovery": True,
                },
            },
        )
    ]
