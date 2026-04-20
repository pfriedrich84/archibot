"""Tests for the LLM-as-judge verification pass in classifier.verify()."""

from __future__ import annotations

from unittest.mock import AsyncMock

import pytest

from app.models import ClassificationResult, JudgeVerdict, PaperlessDocument
from app.pipeline.classifier import (
    _parse_judge_verdict,
    build_judge_user_prompt,
    verify,
)


def _initial_result() -> ClassificationResult:
    return ClassificationResult(
        title="Original Title",
        date="2024-01-02",
        correspondent="Stadtwerke München",
        document_type="Rechnung",
        storage_path="Finanzen/Rechnungen",
        tags=[{"name": "Finanzen", "confidence": 80}],
        confidence=55,
        reasoning="initial guess",
    )


# ---------------------------------------------------------------------------
# _parse_judge_verdict
# ---------------------------------------------------------------------------
class TestParseJudgeVerdict:
    def test_agree(self):
        doc = PaperlessDocument(id=1, title="t", content="c")
        v = _parse_judge_verdict({"verdict": "agree", "reasoning": "looks good"}, target=doc)
        assert v.verdict == "agree"
        assert v.reasoning == "looks good"
        assert v.corrected is None

    def test_corrected_populates_full_result(self):
        doc = PaperlessDocument(id=1, title="t", content="c")
        payload = {
            "verdict": "corrected",
            "title": "Better Title",
            "date": "2024-03-15",
            "correspondent": "EnBW",
            "document_type": "Rechnung",
            "storage_path": None,
            "tags": [{"name": "Strom", "confidence": 90}],
            "confidence": 88,
            "reasoning": "context clearly shows EnBW",
        }
        v = _parse_judge_verdict(payload, target=doc)
        assert v.verdict == "corrected"
        assert v.corrected is not None
        assert v.corrected.title == "Better Title"
        assert v.corrected.correspondent == "EnBW"
        assert v.corrected.confidence == 88

    def test_unknown_verdict_becomes_error(self):
        doc = PaperlessDocument(id=1, title="t", content="c")
        v = _parse_judge_verdict({"verdict": "maybe"}, target=doc)
        assert v.verdict == "error"
        assert v.corrected is None

    def test_corrected_with_invalid_payload_becomes_error(self):
        """If the 'corrected' payload fails validation, we return error instead of crashing."""
        doc = PaperlessDocument(id=1, title="t", content="c")
        # Missing required 'title' field in the corrected result
        v = _parse_judge_verdict({"verdict": "corrected", "confidence": 80}, target=doc)
        assert v.verdict == "error"
        assert v.corrected is None

    def test_reasoning_is_trimmed_to_300_chars(self):
        doc = PaperlessDocument(id=1, title="t", content="c")
        long_reason = "x" * 500
        v = _parse_judge_verdict({"verdict": "agree", "reasoning": long_reason}, target=doc)
        assert len(v.reasoning) == 300


# ---------------------------------------------------------------------------
# build_judge_user_prompt — verifies the proposal appendix is present
# ---------------------------------------------------------------------------
class TestBuildJudgeUserPrompt:
    def test_prompt_contains_proposal_and_target_sections(
        self,
        sample_doc,
        sample_correspondents,
        sample_doctypes,
        sample_storage_paths,
        sample_tags,
    ):
        prompt = build_judge_user_prompt(
            target=sample_doc,
            context_docs=[],
            initial=_initial_result(),
            correspondents=sample_correspondents,
            doctypes=sample_doctypes,
            storage_paths=sample_storage_paths,
            tags=sample_tags,
            num_ctx=8192,
            system_prompt_chars=1000,
        )
        assert "Bestehender Klassifikations-Vorschlag" in prompt
        # The serialized proposal should include the original title
        assert "Original Title" in prompt
        # Base classify prompt is still there
        assert "# Zu klassifizierendes Dokument" in prompt


# ---------------------------------------------------------------------------
# verify() — orchestrates the Ollama call + parsing
# ---------------------------------------------------------------------------
class TestVerify:
    @pytest.mark.asyncio
    async def test_verify_agree_roundtrip(
        self,
        sample_doc,
        sample_correspondents,
        sample_doctypes,
        sample_storage_paths,
        sample_tags,
    ):
        ollama = AsyncMock()
        ollama.chat_json = AsyncMock(
            return_value={"verdict": "agree", "reasoning": "context matches"}
        )
        ollama.model = "gemma4:e4b"

        v: JudgeVerdict = await verify(
            sample_doc,
            [],
            _initial_result(),
            sample_correspondents,
            sample_doctypes,
            sample_storage_paths,
            sample_tags,
            ollama,
        )
        assert v.verdict == "agree"
        assert v.corrected is None
        assert ollama.chat_json.await_count == 1

    @pytest.mark.asyncio
    async def test_verify_corrected_returns_new_result(
        self,
        sample_doc,
        sample_correspondents,
        sample_doctypes,
        sample_storage_paths,
        sample_tags,
    ):
        ollama = AsyncMock()
        ollama.chat_json = AsyncMock(
            return_value={
                "verdict": "corrected",
                "title": "Replaced",
                "date": "2024-06-01",
                "correspondent": "Deutsche Post",
                "document_type": "Rechnung",
                "storage_path": None,
                "tags": [{"name": "Finanzen", "confidence": 85}],
                "confidence": 90,
                "reasoning": "context overrides",
            }
        )
        ollama.model = "gemma4:e4b"

        v = await verify(
            sample_doc,
            [],
            _initial_result(),
            sample_correspondents,
            sample_doctypes,
            sample_storage_paths,
            sample_tags,
            ollama,
        )
        assert v.verdict == "corrected"
        assert v.corrected is not None
        assert v.corrected.title == "Replaced"
        assert v.corrected.correspondent == "Deutsche Post"

    @pytest.mark.asyncio
    async def test_verify_transport_error_becomes_error_verdict(
        self,
        sample_doc,
        sample_correspondents,
        sample_doctypes,
        sample_storage_paths,
        sample_tags,
    ):
        """Ollama failures must not raise — caller keeps the initial result."""
        ollama = AsyncMock()
        ollama.chat_json = AsyncMock(side_effect=RuntimeError("boom"))
        ollama.model = "gemma4:e4b"

        v = await verify(
            sample_doc,
            [],
            _initial_result(),
            sample_correspondents,
            sample_doctypes,
            sample_storage_paths,
            sample_tags,
            ollama,
        )
        assert v.verdict == "error"
        assert "boom" in v.reasoning
