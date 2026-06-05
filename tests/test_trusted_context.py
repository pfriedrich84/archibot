from app.config import settings
from app.models import PaperlessDocument
from app.pipeline.trusted_context import is_trusted_document, trusted_context_scope


def test_trusted_document_uses_absence_of_inbox_tag(monkeypatch):
    monkeypatch.setattr(settings, "paperless_inbox_tag_id", 9)

    assert is_trusted_document(PaperlessDocument(id=1, title="Trusted", tags=[1, 2]))
    assert not is_trusted_document(PaperlessDocument(id=2, title="Inbox", tags=[2, 9]))


def test_trusted_document_trusts_all_when_no_inbox_tag_configured(monkeypatch):
    monkeypatch.setattr(settings, "paperless_inbox_tag_id", None)

    assert is_trusted_document(PaperlessDocument(id=3, title="Any", tags=[9]))


def test_trusted_document_ignores_empty_or_malformed_tags(monkeypatch):
    monkeypatch.setattr(settings, "paperless_inbox_tag_id", 9)

    assert is_trusted_document(PaperlessDocument(id=4, title="Missing", tags=[]))
    assert is_trusted_document(type("Doc", (), {"tags": None})())


def test_trusted_document_accepts_expanded_paperless_tag_objects(monkeypatch):
    monkeypatch.setattr(settings, "paperless_inbox_tag_id", 9)

    trusted = PaperlessDocument.model_validate({"id": 5, "title": "Trusted", "tags": [{"id": 1}]})
    inbox = type("Doc", (), {"tags": [{"id": 9, "name": "Inbox"}]})()

    assert trusted.tags == [1]
    assert is_trusted_document(trusted)
    assert not is_trusted_document(inbox)


def test_trusted_context_scope_names_domain_rule():
    assert trusted_context_scope() == "without_inbox_tag"
