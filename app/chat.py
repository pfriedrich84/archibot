"""Shared RAG chat core — session management and ask() pipeline.

Used by both the web route (``app.routes.chat``) and the Telegram handler
(``app.telegram_handler``) so the RAG logic lives in one place.
"""

from __future__ import annotations

import html
import re
import time
import uuid
from dataclasses import dataclass, field
from datetime import UTC, datetime
from typing import Any, Literal

import structlog

from app.clients.ollama import OllamaClient
from app.clients.paperless import PaperlessClient
from app.config import settings
from app.models import PaperlessEntity
from app.pipeline.classifier import (
    _estimate_tokens,
    _format_context_block,
    _tokens_to_chars,
)
from app.pipeline.context_builder import SimilarDocument, find_similar_by_query_text
from app.prompt_store import load_prompt

log = structlog.get_logger(__name__)

SESSION_TTL = 3600  # 1 hour
MAX_HISTORY = 20  # max messages per session (10 exchanges)


# ---------------------------------------------------------------------------
# Session management
# ---------------------------------------------------------------------------
@dataclass
class _EntityCache:
    """Cached Paperless entity lists — fetched once per session."""

    correspondents: list[PaperlessEntity] = field(default_factory=list)
    doctypes: list[PaperlessEntity] = field(default_factory=list)
    storage_paths: list[PaperlessEntity] = field(default_factory=list)
    tags: list[PaperlessEntity] = field(default_factory=list)
    loaded: bool = False


@dataclass
class ChatSession:
    messages: list[dict[str, Any]] = field(default_factory=list)
    last_active: float = field(default_factory=time.time)
    created_at: float = field(default_factory=time.time)
    origin: Literal["web", "telegram"] = "web"
    entity_cache: _EntityCache = field(default_factory=_EntityCache)


@dataclass
class ChatResult:
    answer: str
    sources: list[dict] = field(default_factory=list)  # [{id, title, distance}]


_sessions: dict[str, ChatSession] = {}


def _expire_sessions() -> None:
    """Remove sessions older than TTL."""
    now = time.time()
    expired = [sid for sid, s in _sessions.items() if now - s.last_active > SESSION_TTL]
    for sid in expired:
        del _sessions[sid]


def get_or_create_session(
    session_id: str | None, *, origin: Literal["web", "telegram"] = "web"
) -> tuple[str, ChatSession]:
    """Return (session_id, session). Creates a runtime-only in-memory session."""
    _expire_sessions()
    if session_id and session_id in _sessions:
        session = _sessions[session_id]
        session.last_active = time.time()
        return session_id, session
    new_id = session_id if origin == "telegram" and session_id else uuid.uuid4().hex[:16]
    session = ChatSession(origin=origin)
    _sessions[new_id] = session
    return new_id, session


def _iso_from_ts(ts: float) -> str:
    return datetime.fromtimestamp(ts, tz=UTC).isoformat()


def _session_title(session: ChatSession) -> str:
    first_user = next((m["content"] for m in session.messages if m.get("role") == "user"), "")
    title = " ".join(first_user.split())
    if not title:
        return "Telegram Chat" if session.origin == "telegram" else "Neuer Chat"
    return title[:77] + "..." if len(title) > 80 else title


def _session_preview(session: ChatSession) -> str:
    last = session.messages[-1]["content"] if session.messages else ""
    preview = " ".join(last.split())
    return preview[:117] + "..." if len(preview) > 120 else preview


def list_chat_sessions(limit: int = 20) -> list[dict[str, object]]:
    """Return completed sessions for the latest-chat overview."""
    _expire_sessions()
    completed = [
        (sid, session)
        for sid, session in _sessions.items()
        if any(m.get("role") == "assistant" for m in session.messages)
    ]
    completed.sort(key=lambda item: item[1].last_active, reverse=True)
    return [
        {
            "id": sid,
            "title": _session_title(session),
            "preview": _session_preview(session),
            "origin": session.origin,
            "last_active": _iso_from_ts(session.last_active),
            "message_count": len(session.messages),
        }
        for sid, session in completed[:limit]
    ]


def get_chat_session_snapshot(session_id: str) -> dict[str, object] | None:
    _expire_sessions()
    session = _sessions.get(session_id)
    if not session:
        return None
    session.last_active = time.time()
    return {
        "id": session_id,
        "title": _session_title(session),
        "origin": session.origin,
        "last_active": _iso_from_ts(session.last_active),
        "messages": list(session.messages),
    }


def delete_chat_session(session_id: str) -> bool:
    _expire_sessions()
    return _sessions.pop(session_id, None) is not None


# ---------------------------------------------------------------------------
# Prompt helpers
# ---------------------------------------------------------------------------
def load_chat_system_prompt() -> str:
    """Load chat system prompt — user override in /data takes precedence."""
    return load_prompt("chat")


def markdown_to_telegram_html(markdown: str) -> str:
    """Render a safe markdown subset to Telegram-compatible HTML."""
    text = html.escape(markdown)

    code_blocks: list[str] = []

    def stash_code_block(match: re.Match[str]) -> str:
        code_blocks.append(f"<pre>{match.group(1).strip()}</pre>")
        return f"\u0000CODE{len(code_blocks) - 1}\u0000"

    text = re.sub(r"```(?:\w+)?\n?(.*?)```", stash_code_block, text, flags=re.DOTALL)
    text = re.sub(r"`([^`]+)`", r"<code>\1</code>", text)
    text = re.sub(r"\*\*([^*]+)\*\*", r"<b>\1</b>", text)
    text = re.sub(r"(?<!\*)\*([^*\n]+)\*(?!\*)", r"<i>\1</i>", text)

    def link(match: re.Match[str]) -> str:
        label = match.group(1)
        url = html.unescape(match.group(2))
        if not url.startswith(("http://", "https://")):
            return label
        return f'<a href="{html.escape(url, quote=True)}">{label}</a>'

    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", link, text)
    text = re.sub(r"^#{1,6}\s+(.+)$", r"<b>\1</b>", text, flags=re.MULTILINE)
    for index, block in enumerate(code_blocks):
        text = text.replace(f"\u0000CODE{index}\u0000", block)
    return text


def _build_chat_user_message(question: str, context: str) -> str:
    """Combine user question with document context."""
    if context:
        return f"# Relevante Dokumente\n\n{context}\n\n# Frage des Benutzers\n\n{question}"
    return question


def _budget_context_blocks(
    similar: list[SimilarDocument],
    system_prompt: str,
    history: list[dict[str, Any]],
    question: str,
    correspondents: list[PaperlessEntity],
    doctypes: list[PaperlessEntity],
    storage_paths: list[PaperlessEntity],
    tags: list[PaperlessEntity],
) -> str:
    """Build context text for chat using dynamic token budgeting.

    Mirrors the allocation strategy from ``classifier.build_user_prompt``:
    estimate token usage for fixed parts (system prompt, history, question)
    and distribute the remaining budget across context documents.
    """
    if not similar:
        return ""

    RESPONSE_RESERVE = 512
    MIN_DOC_TOKENS = 100
    num_ctx = settings.ollama_num_ctx

    system_tokens = _estimate_tokens(system_prompt)
    history_tokens = sum(_estimate_tokens(m["content"]) for m in history)
    question_tokens = _estimate_tokens(question)
    fixed_tokens = system_tokens + history_tokens + question_tokens + 80  # overhead

    available_tokens = int((num_ctx - RESPONSE_RESERVE - fixed_tokens) * 0.85)
    if available_tokens < 200:
        available_tokens = 200

    active = list(similar)
    while active:
        per_doc = available_tokens // len(active)
        if per_doc >= MIN_DOC_TOKENS:
            break
        active.pop()  # drop least-similar (last) doc

    if not active:
        return ""

    chars_per_doc = _tokens_to_chars(available_tokens // len(active))

    blocks = []
    for sim in active:
        block = _format_context_block(
            sim.document, chars_per_doc, correspondents, doctypes, storage_paths, tags
        )
        blocks.append(block)
    return "\n".join(blocks)


async def _ensure_entity_cache(session: ChatSession, paperless: PaperlessClient) -> _EntityCache:
    """Fetch entity lists once per session, then reuse from cache."""
    cache = session.entity_cache
    if cache.loaded:
        return cache
    try:
        cache.correspondents = await paperless.list_correspondents()
        cache.doctypes = await paperless.list_document_types()
        cache.storage_paths = await paperless.list_storage_paths()
        cache.tags = await paperless.list_tags()
    except Exception as exc:
        log.warning("failed to fetch entity lists for chat", error=str(exc))
    cache.loaded = True
    return cache


# ---------------------------------------------------------------------------
# RAG pipeline
# ---------------------------------------------------------------------------
async def ask(
    question: str,
    session: ChatSession,
    paperless: PaperlessClient,
    ollama: OllamaClient,
) -> ChatResult:
    """Full RAG pipeline: embed -> vector search -> format context -> LLM -> answer.

    Appends the plain Q&A to *session.messages* (not the context-augmented
    prompt) so history stays compact.
    """
    # 1. Find similar documents via vector search
    similar = await find_similar_by_query_text(
        question, paperless, ollama, limit=settings.context_max_docs
    )

    # 2. Build context block with dynamic token budgeting
    system_prompt = load_chat_system_prompt()
    context_text = ""
    if similar:
        entities = await _ensure_entity_cache(session, paperless)
        context_text = _budget_context_blocks(
            similar,
            system_prompt,
            session.messages,
            question,
            entities.correspondents,
            entities.doctypes,
            entities.storage_paths,
            entities.tags,
        )

    # 3. Build messages list
    user_content = _build_chat_user_message(question, context_text)

    messages: list[dict[str, str]] = [{"role": "system", "content": system_prompt}]
    for msg in session.messages:
        messages.append({"role": msg["role"], "content": msg["content"]})
    messages.append({"role": "user", "content": user_content})

    # 4. Call Ollama
    try:
        answer = await ollama.chat(messages)
    except Exception as exc:
        log.error("chat LLM call failed", error=str(exc))
        answer = "Fehler bei der Verarbeitung. Bitte später erneut versuchen."

    # 5. Build sources list
    sources = [
        {"id": s.document.id, "title": s.document.title, "distance": round(s.distance, 3)}
        for s in similar
    ]

    # 6. Update session history (plain Q&A content plus display metadata)
    session.messages.append({"role": "user", "content": question})
    session.messages.append({"role": "assistant", "content": answer, "sources": sources})
    session.last_active = time.time()
    if len(session.messages) > MAX_HISTORY:
        session.messages = session.messages[-MAX_HISTORY:]

    return ChatResult(answer=answer, sources=sources)
