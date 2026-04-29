from __future__ import annotations

from app.models import PaperlessDocument, PaperlessEntity
from app.pipeline.ocr_correction import should_run_ocr_for_document


def test_no_filter_configured_runs_for_all_documents(monkeypatch):
    monkeypatch.setattr("app.pipeline.ocr_correction.settings.ocr_requested_tag_id", 0)
    doc = PaperlessDocument(id=1, title="Doc", tags=[])

    eligible, reason = should_run_ocr_for_document(doc)

    assert eligible is True
    assert reason == "no_filter"


def test_configured_tag_present_runs_ocr(monkeypatch):
    monkeypatch.setattr("app.pipeline.ocr_correction.settings.ocr_requested_tag_id", 124)
    doc = PaperlessDocument(id=1, title="Doc", tags=[124, 5])

    eligible, reason = should_run_ocr_for_document(doc)

    assert eligible is True
    assert reason == "tag_present"


def test_configured_tag_absent_skips_ocr(monkeypatch):
    monkeypatch.setattr("app.pipeline.ocr_correction.settings.ocr_requested_tag_id", 124)
    doc = PaperlessDocument(id=1, title="Doc", tags=[5])

    eligible, reason = should_run_ocr_for_document(doc)

    assert eligible is False
    assert reason == "tag_absent"


def test_deleted_configured_tag_skips_ocr(monkeypatch):
    monkeypatch.setattr("app.pipeline.ocr_correction.settings.ocr_requested_tag_id", 124)
    doc = PaperlessDocument(id=1, title="Doc", tags=[124])
    available_tags = [PaperlessEntity(id=5, name="Other")]

    eligible, reason = should_run_ocr_for_document(doc, available_tags=available_tags)

    assert eligible is False
    assert reason == "configured_tag_missing"


def test_webhook_without_tag_info_skips_ocr(monkeypatch):
    monkeypatch.setattr("app.pipeline.ocr_correction.settings.ocr_requested_tag_id", 124)
    doc = PaperlessDocument(id=1, title="Doc", tags=[])

    eligible, reason = should_run_ocr_for_document(doc, require_tag_info=True)

    assert eligible is False
    assert reason == "document_tags_missing"
