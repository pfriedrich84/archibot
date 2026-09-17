from types import SimpleNamespace

import pytest

from app.jobs import review_commit


class FakeResult:
    def __init__(self, row=None):
        self.row = row or {"id": 8}

    def mappings(self):
        return self

    def first(self):
        return self.row

    def all(self):
        return [self.row]


class FakeConnection:
    def __init__(self, calls, row=None):
        self.calls = calls
        self.row = row

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, traceback):
        return None

    def execute(self, statement, params):
        self.calls.append((statement, params))
        return FakeResult(self.row)


class FakeEngine:
    def __init__(self, calls, row=None):
        self.calls = calls
        self.row = row

    def connect(self):
        return FakeConnection(self.calls, self.row)

    def begin(self):
        return FakeConnection(self.calls, self.row)


def test_list_review_suggestions_ready_to_commit(monkeypatch):
    calls = []
    monkeypatch.setattr(review_commit, "engine", lambda: FakeEngine(calls, {"id": 8}))
    monkeypatch.setattr(review_commit, "sql_text", lambda statement: statement)

    assert review_commit.list_review_suggestions_ready_to_commit(limit=5) == [8]
    assert calls[0][1] == {"limit": 5}


def test_build_paperless_patch_respects_existing_storage_path_immutability():
    record = review_commit.ReviewCommitRecord(
        id=1,
        paperless_document_id=42,
        paperless_version_id=77,
        paperless_version_checksum="abc",
        proposed_title="Title",
        proposed_date="2026-05-08",
        proposed_correspondent_id=1,
        proposed_document_type_id=2,
        proposed_storage_path_id=3,
        proposed_tags=[{"id": 9}, {"name": "unresolved"}],
    )

    fields = review_commit.build_paperless_patch(record, current_tags=[4], current_storage_path=99)

    assert fields == {
        "title": "Title",
        "created": "2026-05-08",
        "correspondent": 1,
        "document_type": 2,
        "tags": [4, 9],
    }


def test_build_paperless_patch_never_assigns_configured_ocr_tag(monkeypatch):
    monkeypatch.setattr("app.pipeline.ocr_correction.settings.ocr_requested_tag_id", 9)
    record = review_commit.ReviewCommitRecord(
        id=1,
        paperless_document_id=42,
        proposed_title=None,
        proposed_date=None,
        proposed_correspondent_id=None,
        proposed_document_type_id=None,
        proposed_storage_path_id=None,
        proposed_tags=[{"id": 9}, {"id": 10}],
    )

    fields = review_commit.build_paperless_patch(
        record, current_tags=[4], current_storage_path=None
    )

    assert fields == {"tags": [4, 10]}


def test_build_paperless_patch_never_assigns_inbox_descendants():
    record = review_commit.ReviewCommitRecord(
        id=1,
        paperless_document_id=42,
        proposed_title=None,
        proposed_date=None,
        proposed_correspondent_id=None,
        proposed_document_type_id=None,
        proposed_storage_path_id=None,
        proposed_tags=[{"id": 10}, {"id": 11}, {"id": 12}],
    )

    fields = review_commit.build_paperless_patch(
        record,
        current_tags=[4],
        current_storage_path=None,
        forbidden_tag_ids={9, 10, 11},
    )

    assert fields == {"tags": [4, 12]}


def test_build_paperless_patch_sets_absent_storage_path_after_manual_review():
    record = review_commit.ReviewCommitRecord(
        id=1,
        paperless_document_id=42,
        paperless_version_id=77,
        paperless_version_checksum="abc",
        proposed_title=None,
        proposed_date=None,
        proposed_correspondent_id=None,
        proposed_document_type_id=None,
        proposed_storage_path_id=3,
        proposed_tags=[],
    )

    assert review_commit.build_paperless_patch(
        record, current_tags=[], current_storage_path=None
    ) == {"storage_path": 3}


@pytest.mark.asyncio
async def test_commit_review_suggestion_to_paperless_patches_fields():
    patched = []

    class FakePaperless:
        async def get_document(self, document_id):
            return SimpleNamespace(
                tags=[4], storage_path=None, current_version_id=77, current_version_checksum="abc"
            )

        async def list_tags(self):
            return []

        async def patch_reviewed_document(self, document_id, fields):
            patched.append((document_id, fields))

    record = review_commit.ReviewCommitRecord(
        id=1,
        paperless_document_id=42,
        paperless_version_id=77,
        paperless_version_checksum="abc",
        proposed_title="Title",
        proposed_date=None,
        proposed_correspondent_id=None,
        proposed_document_type_id=None,
        proposed_storage_path_id=3,
        proposed_tags=[{"id": 9}],
    )

    fields = await review_commit.commit_review_suggestion_to_paperless(record, FakePaperless())

    assert fields == {"title": "Title", "storage_path": 3, "tags": [4, 9]}
    assert patched == [(42, fields)]


@pytest.mark.asyncio
async def test_commit_review_suggestion_to_paperless_fails_closed_on_version_mismatch():
    class FakePaperless:
        async def get_document(self, document_id):
            return SimpleNamespace(
                title="Different",
                document_date=None,
                correspondent=None,
                document_type=None,
                tags=[4],
                storage_path=None,
                current_version_id=78,
                current_version_checksum="abc",
            )

        async def list_tags(self):
            return []

    record = review_commit.ReviewCommitRecord(
        id=1,
        paperless_document_id=42,
        paperless_version_id=77,
        paperless_version_checksum="abc",
        proposed_title="Title",
        proposed_date=None,
        proposed_correspondent_id=None,
        proposed_document_type_id=None,
        proposed_storage_path_id=3,
        proposed_tags=[{"id": 9}],
    )

    with pytest.raises(ValueError, match="version changed"):
        await review_commit.commit_review_suggestion_to_paperless(record, FakePaperless())


@pytest.mark.asyncio
async def test_retry_after_successful_patch_is_recognized_as_committed():
    patched = []

    class FakePaperless:
        async def get_document(self, document_id):
            return SimpleNamespace(
                title="Reviewed title",
                document_date="2026-05-08",
                correspondent=1,
                document_type=2,
                tags=[4, 9],
                storage_path=3,
                current_version_id=78,
                current_version_checksum="changed-by-patch",
            )

        async def list_tags(self):
            return []

        async def patch_reviewed_document(self, document_id, fields):
            patched.append((document_id, fields))

    record = review_commit.ReviewCommitRecord(
        id=1,
        paperless_document_id=42,
        paperless_version_id=77,
        paperless_version_checksum="before-patch",
        proposed_title="Reviewed title",
        proposed_date="2026-05-08",
        proposed_correspondent_id=1,
        proposed_document_type_id=2,
        proposed_storage_path_id=3,
        proposed_tags=[{"id": 9}],
    )

    fields = await review_commit.commit_review_suggestion_to_paperless(record, FakePaperless())

    assert fields == {}
    assert patched == []
