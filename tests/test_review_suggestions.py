from types import SimpleNamespace

from app.jobs import review_suggestions
from app.models import ClassificationResult, ProposedTag


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
