from types import SimpleNamespace

from app.jobs import review_suggestions
from app.models import ClassificationResult, PaperlessEntity, ProposedTag


class FakeResult:
    def __init__(self, row=None):
        self.row = row or {"id": 12, "status": "pending"}

    def mappings(self):
        return self

    def first(self):
        return self.row


class FakeConnection:
    def __init__(self, calls):
        self.calls = calls

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, traceback):
        return None

    def execute(self, statement, params):
        self.calls.append((statement, params))
        return FakeResult()


class FakeEngine:
    def __init__(self, calls):
        self.calls = calls

    def begin(self):
        return FakeConnection(self.calls)


def test_store_review_suggestion_inserts_pending_laravel_review(monkeypatch):
    calls = []
    monkeypatch.setattr(review_suggestions, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(review_suggestions, "sql_text", lambda statement: statement)

    stored = review_suggestions.store_review_suggestion(
        paperless_document_id=42,
        document=SimpleNamespace(
            title="Original",
            created_date="2026-05-08",
            correspondent=1,
            document_type=2,
            storage_path=None,
            tags=[3, 4],
        ),
        result=ClassificationResult(
            title="Proposed",
            date="2026-05-07",
            correspondent="Corr",
            document_type="Invoice",
            storage_path="Invoices/2026",
            tags=[ProposedTag(name="Paid", confidence=80)],
            confidence=91,
            reasoning="Looks like an invoice.",
        ),
        raw_response='{"title":"Proposed"}',
        context_documents=[],
        pipeline_run_id=99,
    )

    assert stored == review_suggestions.StoredReviewSuggestion(id=12, status="pending")
    params = calls[0][1]
    assert params["paperless_document_id"] == 42
    assert params["confidence"] == 91
    assert params["original_title"] == "Original"
    assert params["proposed_title"] == "Proposed"
    assert params["proposed_correspondent_name"] == "Corr"
    assert '"name": "Paid"' in params["proposed_tags"]
    assert params["pipeline_run_id"] == 99


class SequenceConnection:
    def __init__(self, calls, rows):
        self.calls = calls
        self.rows = list(rows)

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, traceback):
        return None

    def execute(self, statement, params):
        self.calls.append((statement, params))
        row = self.rows.pop(0) if self.rows else None
        return FakeResult(row)


class SequenceEngine:
    def __init__(self, calls, rows):
        self.calls = calls
        self.rows = rows

    def begin(self):
        return SequenceConnection(self.calls, self.rows)

    def connect(self):
        return SequenceConnection(self.calls, self.rows)


def test_store_review_suggestion_resolves_known_entities_and_stages_unknown(monkeypatch):
    calls = []
    monkeypatch.setattr(review_suggestions, "engine", lambda: FakeEngine(calls))
    monkeypatch.setattr(review_suggestions, "sql_text", lambda statement: statement)

    review_suggestions.store_review_suggestion(
        paperless_document_id=42,
        document=SimpleNamespace(title="Original", created_date=None, correspondent=None, document_type=None, storage_path=None, tags=[]),
        result=ClassificationResult(
            title="Proposed",
            correspondent="Known Corr",
            document_type="Unknown Type",
            storage_path="Known Path",
            tags=[ProposedTag(name="Known Tag", confidence=90), ProposedTag(name="New Tag", confidence=80)],
            confidence=91,
        ),
        raw_response="{}",
        context_documents=[],
        pipeline_run_id=99,
        correspondents=[PaperlessEntity(id=1, name="Known Corr")],
        doctypes=[],
        storage_paths=[PaperlessEntity(id=3, name="Known Path")],
        tags=[PaperlessEntity(id=4, name="Known Tag")],
    )

    params = calls[0][1]
    assert params["proposed_correspondent_id"] == 1
    assert params["proposed_document_type_id"] is None
    assert params["proposed_storage_path_id"] == 3
    assert '"id": 4' in params["proposed_tags"]
    approval_params = [params for _, params in calls[1:]]
    assert any(item["type"] == "document_type" and item["name"] == "Unknown Type" for item in approval_params)
    assert any(item["type"] == "tag" and item["name"] == "New Tag" for item in approval_params)


def test_mark_review_suggestion_auto_accepted_queues_only_when_resolved(monkeypatch):
    calls = []
    rows = [
        {
            "proposed_correspondent_name": "Corr",
            "proposed_correspondent_id": 1,
            "proposed_document_type_name": None,
            "proposed_document_type_id": None,
            "proposed_storage_path_name": None,
            "proposed_storage_path_id": None,
            "proposed_tags": [{"name": "Known", "id": 2}],
        },
        None,
    ]
    monkeypatch.setattr(review_suggestions, "engine", lambda: SequenceEngine(calls, rows))
    monkeypatch.setattr(review_suggestions, "sql_text", lambda statement: statement)

    accepted, unresolved = review_suggestions.mark_review_suggestion_auto_accepted(
        12, reason="auto_commit_confidence", confidence=91
    )

    assert accepted is True
    assert unresolved == []
    assert calls[1][1]["review_suggestion_id"] == 12
    assert "auto_commit" in calls[1][1]["auto_payload"]


def test_mark_review_suggestion_auto_accepted_skips_unresolved(monkeypatch):
    calls = []
    rows = [
        {
            "proposed_correspondent_name": "New Corr",
            "proposed_correspondent_id": None,
            "proposed_document_type_name": None,
            "proposed_document_type_id": None,
            "proposed_storage_path_name": None,
            "proposed_storage_path_id": None,
            "proposed_tags": [],
        }
    ]
    monkeypatch.setattr(review_suggestions, "engine", lambda: SequenceEngine(calls, rows))
    monkeypatch.setattr(review_suggestions, "sql_text", lambda statement: statement)

    accepted, unresolved = review_suggestions.mark_review_suggestion_auto_accepted(
        12, reason="auto_commit_confidence", confidence=91
    )

    assert accepted is False
    assert unresolved == ["correspondent"]
    assert len(calls) == 1
