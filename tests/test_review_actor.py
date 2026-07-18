import pytest

from app.actors import review
from app.jobs.actor_execution import ActorExecutionHandle
from app.jobs.review_commit import ReviewCommitRecord


def _capture_progress(monkeypatch):
    progresses = []
    monkeypatch.setattr(
        review,
        "update_actor_execution_progress",
        lambda *args, **kwargs: progresses.append((args, kwargs)),
    )
    return progresses


def test_commit_review_suggestion_actor_commits_and_marks_status(monkeypatch):
    statuses = []
    finishes = []
    events = []
    progresses = _capture_progress(monkeypatch)

    monkeypatch.setattr(
        review,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=4, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )
    monkeypatch.setattr(
        review,
        "load_review_commit",
        lambda review_suggestion_id: ReviewCommitRecord(
            id=review_suggestion_id,
            paperless_document_id=42,
            proposed_title="Title",
            proposed_date=None,
            proposed_correspondent_id=None,
            proposed_document_type_id=None,
            proposed_storage_path_id=None,
            proposed_tags=[],
        ),
    )

    class FakeCoroutine:
        def close(self):
            return None

    monkeypatch.setattr(review, "mark_review_commit_status", lambda *args: statuses.append(args))
    monkeypatch.setattr(review, "commit_record", lambda record: FakeCoroutine())
    monkeypatch.setattr(review, "run_async", lambda coroutine: {"title": "Title"})
    monkeypatch.setattr(
        review, "finish_actor_execution", lambda *args, **kwargs: finishes.append((args, kwargs))
    )
    monkeypatch.setattr(
        review, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )

    review._commit_review_suggestion_impl(12, 77)

    assert statuses == [(12, "running"), (12, "committed")]
    assert events[0][0] == ("review.commit.succeeded",)
    assert events[0][1]["paperless_document_id"] == 42
    assert events[0][1]["payload"]["command_id"] == 77
    assert finishes[0][1] == {"status": "succeeded"}
    assert [call[0][1].phase for call in progresses] == [
        "review_commit_load",
        "review_commit_paperless",
        "review_commit_finished",
    ]


def test_commit_review_suggestion_actor_schedules_retry_for_transient_failure(monkeypatch):
    statuses = []
    retries = []
    events = []
    _capture_progress(monkeypatch)

    monkeypatch.setattr(
        review,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=4, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )
    monkeypatch.setattr(
        review,
        "load_review_commit",
        lambda review_suggestion_id: ReviewCommitRecord(
            id=review_suggestion_id,
            paperless_document_id=42,
            proposed_title="Title",
            proposed_date=None,
            proposed_correspondent_id=None,
            proposed_document_type_id=None,
            proposed_storage_path_id=None,
            proposed_tags=[],
        ),
    )

    class FakeCoroutine:
        def close(self):
            return None

    monkeypatch.setattr(review, "mark_review_commit_status", lambda *args: statuses.append(args))
    monkeypatch.setattr(review, "commit_record", lambda record: FakeCoroutine())
    monkeypatch.setattr(
        review,
        "run_async",
        lambda coroutine: (_ for _ in ()).throw(TimeoutError("paperless slow")),
    )
    monkeypatch.setattr(
        "app.execution_lifecycle.execution_store.schedule_actor_execution_retry",
        lambda *args, **kwargs: retries.append((args, kwargs)),
    )
    monkeypatch.setattr(
        review, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )

    with pytest.raises(TimeoutError):
        review._commit_review_suggestion_impl(12, 77)

    assert statuses == [(12, "running"), (12, "retrying", "transient_network")]
    assert retries[0][1] == {
        "retry_class": "transient_network",
        "retry_reason": "TimeoutError",
        "backoff_seconds": 30,
        "error_message": "paperless slow",
    }
    assert events == []


def test_commit_review_suggestion_actor_skips_missing_record(monkeypatch):
    finishes = []
    events = []
    _capture_progress(monkeypatch)

    monkeypatch.setattr(
        review,
        "start_actor_execution",
        lambda **kwargs: ActorExecutionHandle(
            id=4, actor_name=kwargs["actor_name"], started_monotonic=0
        ),
    )
    monkeypatch.setattr(review, "load_review_commit", lambda review_suggestion_id: None)
    monkeypatch.setattr(
        review, "finish_actor_execution", lambda *args, **kwargs: finishes.append((args, kwargs))
    )
    monkeypatch.setattr(
        review, "publish_pipeline_event", lambda *args, **kwargs: events.append((args, kwargs))
    )

    review._commit_review_suggestion_impl(404, 77)

    assert events[0][0] == ("review.commit.skipped",)
    assert finishes[0][1]["status"] == "failed_permanent"
