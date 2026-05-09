from app.actors import review
from app.jobs.actor_execution import ActorExecutionHandle
from app.jobs.review_commit import ReviewCommitRecord


def test_commit_review_suggestion_actor_commits_and_marks_status(monkeypatch):
    statuses = []
    finishes = []
    events = []

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

    review._commit_review_suggestion_impl(12)

    assert statuses == [(12, "running"), (12, "committed")]
    assert events[0][0] == ("review.commit.succeeded",)
    assert events[0][1]["paperless_document_id"] == 42
    assert finishes[0][1] == {"status": "succeeded"}


def test_commit_review_suggestion_actor_skips_missing_record(monkeypatch):
    finishes = []
    events = []

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

    review._commit_review_suggestion_impl(404)

    assert events[0][0] == ("review.commit.skipped",)
    assert finishes[0][1]["status"] == "skipped"
