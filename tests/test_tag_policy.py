from app.models import PaperlessEntity
from app.pipeline.tag_policy import (
    reserved_classification_tag_ids,
    reserved_classification_tag_names,
)


def test_reserves_inbox_tag_and_all_descendants(monkeypatch):
    monkeypatch.setattr("app.pipeline.tag_policy.settings.paperless_inbox_tag_id", 10)
    monkeypatch.setattr("app.pipeline.ocr_correction.settings.ocr_requested_tag_id", 20)
    tags = [
        PaperlessEntity(id=10, name="Inbox"),
        PaperlessEntity(id=11, name="Inbox child", parent=10),
        PaperlessEntity(id=12, name="Nested child", parent=11),
        PaperlessEntity(id=13, name="Business", parent=None),
        PaperlessEntity(id=20, name="OCR", parent=None),
    ]

    assert reserved_classification_tag_ids(tags) == {10, 11, 12, 20}
    assert reserved_classification_tag_names(tags) == {
        "inbox",
        "inbox child",
        "nested child",
        "ocr",
    }


def test_paperless_entity_accepts_expanded_parent():
    tag = PaperlessEntity.model_validate(
        {"id": 11, "name": "Child", "parent": {"id": "10", "name": "Inbox"}}
    )

    assert tag.parent == 10
