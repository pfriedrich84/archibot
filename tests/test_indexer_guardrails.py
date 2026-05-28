from __future__ import annotations

import asyncio
import json
import sqlite3
from contextlib import contextmanager
from pathlib import Path
from unittest.mock import AsyncMock

import pytest

from app import cli, indexer
from app.db import EMBED_DIM
from app.models import PaperlessDocument


@contextmanager
def _conn(path: Path):
    conn = sqlite3.connect(path)
    conn.row_factory = sqlite3.Row
    conn.execute("CREATE TABLE IF NOT EXISTS doc_embedding_meta (document_id INTEGER PRIMARY KEY)")
    conn.execute("CREATE TABLE IF NOT EXISTS audit_log (action TEXT, actor TEXT, details TEXT)")
    try:
        yield conn
    finally:
        conn.close()


def _prepare(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> list[dict[str, object]]:
    events: list[dict[str, object]] = []
    db_path = tmp_path / "indexer.sqlite"
    with _conn(db_path):
        pass

    monkeypatch.setattr(indexer, "get_conn", lambda: _conn(db_path))
    monkeypatch.setattr(indexer, "record_event", lambda *args, **kwargs: None)
    monkeypatch.setattr(indexer, "get_cached_ocr", lambda document_id: None)
    monkeypatch.setattr(
        indexer,
        "store_embedding",
        lambda document, embedding: events.append({"stored": document.id, "embedding": embedding}),
    )

    progress = indexer.get_reindex_progress()
    progress.running = True
    progress.total = 0
    progress.done = 0
    progress.failed = 0
    progress.failed_document_ids = []
    progress.cancelled = False
    progress.error = None
    progress.phase = "embedding"
    progress.job_id = "test-job"
    progress.job_type = "reindex_embed"
    indexer.enable_reindex_progress_stdout(True)
    return events


def _progress_lines(output: str) -> list[dict[str, object]]:
    return [
        json.loads(line.removeprefix("PROGRESS "))
        for line in output.splitlines()
        if line.startswith("PROGRESS ")
    ]


@pytest.fixture(autouse=True)
def _reset_reindex_progress():
    def reset() -> None:
        progress = indexer.get_reindex_progress()
        progress.running = False
        progress.total = 0
        progress.done = 0
        progress.failed = 0
        progress.failed_document_ids = []
        progress.cancelled = False
        progress.error = None
        progress.phase = "idle"
        progress.job_id = None
        progress.job_type = None
        indexer.enable_reindex_progress_stdout(False)

    reset()
    yield
    reset()


@pytest.mark.asyncio
async def test_normal_document_embeds(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    stored = _prepare(monkeypatch, tmp_path)
    document = PaperlessDocument(id=1, title="Invoice", content="short content")
    paperless = AsyncMock()
    paperless.list_all_documents = AsyncMock(return_value=[document])
    ollama = AsyncMock()
    ollama.embed = AsyncMock(return_value=[0.1] * EMBED_DIM)
    ollama.embed_retry_count = 0

    indexed = await indexer.initial_index(paperless, ollama)

    assert indexed == 1
    assert stored == [{"stored": 1, "embedding": [0.1] * EMBED_DIM}]
    ollama.embed.assert_awaited_once()


@pytest.mark.asyncio
async def test_huge_document_is_truncated_and_reports_guardrail(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    _prepare(monkeypatch, tmp_path)
    monkeypatch.setattr(indexer.settings, "embed_max_chars", 20)
    document = PaperlessDocument(id=2, title="Big", content="x" * 200)
    paperless = AsyncMock()
    paperless.list_all_documents = AsyncMock(return_value=[document])
    ollama = AsyncMock()
    ollama.embed = AsyncMock(return_value=[0.1] * EMBED_DIM)
    ollama.embed_retry_count = 0

    indexed = await indexer.initial_index(paperless, ollama)

    assert indexed == 1
    embedded_text = ollama.embed.await_args.args[0]
    assert len(embedded_text) == 20
    lines = _progress_lines(capsys.readouterr().out)
    started = next(line for line in lines if line.get("event") == "document_started")
    assert started["document_id"] == 2
    assert started["content_length"] == 200
    assert started["truncated"] is True
    assert started["embedding_max_chars"] == 20


@pytest.mark.asyncio
async def test_embedding_failure_reports_document_failed_and_continues(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    stored = _prepare(monkeypatch, tmp_path)
    docs = [
        PaperlessDocument(id=3, title="Bad", content="bad"),
        PaperlessDocument(id=4, title="Good", content="good"),
    ]
    paperless = AsyncMock()
    paperless.list_all_documents = AsyncMock(return_value=docs)
    ollama = AsyncMock()
    ollama.embed = AsyncMock(side_effect=[RuntimeError("model unavailable"), [0.1] * EMBED_DIM])
    ollama.embed_retry_count = 0

    indexed = await indexer.initial_index(paperless, ollama)

    assert indexed == 1
    assert stored == [{"stored": 4, "embedding": [0.1] * EMBED_DIM}]
    progress = indexer.get_reindex_progress()
    assert progress.failed == 1
    assert progress.failed_document_ids == [3]
    lines = _progress_lines(capsys.readouterr().out)
    failed = next(line for line in lines if line.get("event") == "document_failed")
    assert failed["document_id"] == 3
    assert failed["error"] == "model unavailable"


@pytest.mark.asyncio
async def test_reindex_embed_result_reports_failed_document_ids(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    async def fake_build_embeddings(
        build_id: int, limit: int | None, actor_execution_id: int | None
    ):
        progress = indexer.get_reindex_progress()
        progress.failed_document_ids = [7]
        return (3, 2, 1)

    monkeypatch.setattr(
        cli,
        "start_embedding_index_build",
        lambda **kwargs: type("Build", (), {"id": 55, "already_running": False})(),
    )
    monkeypatch.setattr(cli, "finish_embedding_index_build", lambda *args, **kwargs: None)
    monkeypatch.setattr(cli, "_build_pgvector_embeddings", fake_build_embeddings)

    result = await cli.cmd_reindex_embed(emit_progress=True, job_id="job-7")

    assert result["indexed"] == 2
    assert result["failed"] == 1
    assert result["failed_document_ids"] == [7]
    assert result["progress"]["failed"] == 1
    assert result["progress"]["failed_document_ids"] == [7]


@pytest.mark.asyncio
async def test_reindex_embed_marks_build_failed_when_pgvector_build_raises(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    finishes = []

    async def fake_build_embeddings(
        build_id: int, limit: int | None, actor_execution_id: int | None
    ):
        raise RuntimeError("ollama unavailable")

    monkeypatch.setattr(
        cli,
        "start_embedding_index_build",
        lambda **kwargs: type("Build", (), {"id": 56, "already_running": False})(),
    )
    monkeypatch.setattr(
        cli,
        "finish_embedding_index_build",
        lambda *args, **kwargs: finishes.append((args, kwargs)),
    )
    monkeypatch.setattr(cli, "_build_pgvector_embeddings", fake_build_embeddings)

    with pytest.raises(RuntimeError, match="ollama unavailable"):
        await cli.cmd_reindex_embed(emit_progress=True, job_id="job-8")

    assert finishes == [((56,), {"status": "failed", "error": "ollama unavailable"})]
    progress = indexer.get_reindex_progress()
    assert progress.running is False
    assert progress.phase == "failed"
    assert progress.error == "ollama unavailable"


@pytest.mark.asyncio
async def test_embedding_timeout_reports_document_failed_and_continues(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    stored = _prepare(monkeypatch, tmp_path)
    monkeypatch.setattr(indexer.settings, "embedding_document_timeout_seconds", 0.01)
    docs = [
        PaperlessDocument(id=5, title="Slow", content="slow"),
        PaperlessDocument(id=6, title="Next", content="next"),
    ]
    paperless = AsyncMock()
    paperless.list_all_documents = AsyncMock(return_value=docs)

    async def embed(text: str) -> list[float]:
        if text.startswith("Slow"):
            await asyncio.sleep(1)
        return [0.1] * EMBED_DIM

    ollama = AsyncMock()
    ollama.embed = AsyncMock(side_effect=embed)
    ollama.embed_retry_count = 0

    indexed = await indexer.initial_index(paperless, ollama)

    assert indexed == 1
    assert stored == [{"stored": 6, "embedding": [0.1] * EMBED_DIM}]
    progress = indexer.get_reindex_progress()
    assert progress.failed == 1
    assert progress.failed_document_ids == [5]
    lines = _progress_lines(capsys.readouterr().out)
    failed = next(line for line in lines if line.get("event") == "document_failed")
    assert failed["document_id"] == 5
    assert failed["error"] == "embedding document timeout"
