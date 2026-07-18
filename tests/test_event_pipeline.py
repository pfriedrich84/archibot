from app.jobs.idempotency import webhook_dedupe_key


def test_webhook_dedupe_key_includes_unknown_modified_marker():
    assert (
        webhook_dedupe_key(
            source="paperless",
            event_type="document.created",
            paperless_document_id=42,
            paperless_modified=None,
            payload_hash="abc123",
        )
        == "paperless:document.created:42:unknown_modified:abc123"
    )
