from __future__ import annotations

import inspect
from types import SimpleNamespace

import pytest

from app.temporal import review_activities
from app.temporal.contracts import ReviewCommitRequest, ReviewCommitResult


def request() -> ReviewCommitRequest:
    return ReviewCommitRequest(34, 15, "archibot/document/261/version")


def test_registered_review_activities_are_async_worker_safe():
    assert inspect.iscoroutinefunction(review_activities.commit_review_suggestion)
    assert inspect.iscoroutinefunction(review_activities.fail_review_commit)


@pytest.mark.asyncio
async def test_commit_activity_patches_once_and_projects_terminal_state(monkeypatch):
    record = SimpleNamespace(id=34)
    finished = []

    class Paperless:
        closed = False

        async def aclose(self):
            self.closed = True

    paperless = Paperless()
    monkeypatch.setattr(review_activities, "_begin_review_commit", lambda _: False)
    monkeypatch.setattr(review_activities, "load_review_commit", lambda _: record)
    monkeypatch.setattr(review_activities, "PaperlessClient", lambda: paperless)

    async def commit(loaded, client):
        assert loaded is record
        assert client is paperless
        return {"title": "Reviewed", "tags": [4, 9]}

    monkeypatch.setattr(review_activities, "commit_review_suggestion_to_paperless", commit)
    monkeypatch.setattr(
        review_activities,
        "_finish_review_commit",
        lambda *args: finished.append(args),
    )

    result = await review_activities.commit_review_suggestion(request())

    assert result == ReviewCommitResult(34, 15, "committed", ["tags", "title"])
    assert finished == [(request(), ["tags", "title"])]
    assert paperless.closed is True


@pytest.mark.asyncio
async def test_committed_activity_retry_is_a_noop(monkeypatch):
    monkeypatch.setattr(review_activities, "_begin_review_commit", lambda _: True)
    monkeypatch.setattr(
        review_activities,
        "PaperlessClient",
        lambda: pytest.fail("Paperless must not be contacted after a committed projection"),
    )

    result = await review_activities.commit_review_suggestion(request())

    assert result == ReviewCommitResult(34, 15, "committed", [])
