import json
from pathlib import Path

from app.jobs.idempotency import pipeline_dedupe_key
from app.jobs.pipeline_start import force_pipeline_dedupe_key

CONTRACT = json.loads(
    (Path(__file__).parent / "fixtures" / "pipeline_start_contract.json").read_text()
)


def test_pipeline_dedupe_key_matches_shared_contract_vectors():
    for vector in CONTRACT["dedupe_vectors"]:
        assert (
            pipeline_dedupe_key(
                paperless_document_id=vector["paperless_document_id"],
                paperless_modified=vector["paperless_modified"],
                content_hash=vector["content_hash"],
                pipeline_version=vector["pipeline_version"],
            )
            == vector["expected_sha256"]
        )


def test_force_pipeline_dedupe_key_matches_shared_contract_vectors():
    for vector in CONTRACT["force_vectors"]:
        assert (
            force_pipeline_dedupe_key(
                paperless_document_id=vector["paperless_document_id"],
                paperless_modified=vector["paperless_modified"],
                content_hash=vector["content_hash"],
                force_token=vector["force_token"],
                pipeline_version=vector["pipeline_version"],
            )
            == vector["expected_sha256"]
        )
