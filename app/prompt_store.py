"""Editable system prompt storage helpers."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

from app.config import settings

MAX_PROMPT_CHARS = 80_000


@dataclass(frozen=True)
class PromptSpec:
    key: str
    filename: str
    label: str
    description: str


PROMPT_SPECS: tuple[PromptSpec, ...] = (
    PromptSpec(
        "classify",
        "classify_system.txt",
        "Klassifikation",
        "System-Prompt für Dokument-Klassifikation und JSON-Vorschläge.",
    ),
    PromptSpec(
        "classify_judge",
        "classify_judge_system.txt",
        "LLM-as-Judge",
        "System-Prompt für die optionale Prüfung unsicherer Klassifikationen.",
    ),
    PromptSpec(
        "ocr_correction",
        "ocr_correction_system.txt",
        "OCR Text-Korrektur",
        "System-Prompt für text-only OCR-Korrektur.",
    ),
    PromptSpec(
        "ocr_vision_light",
        "ocr_vision_light_system.txt",
        "OCR Vision light",
        "System-Prompt für schnelle Vision-OCR-Prüfung.",
    ),
    PromptSpec(
        "ocr_vision_full",
        "ocr_vision_full_system.txt",
        "OCR Vision full",
        "System-Prompt für seitenweise Vision-OCR-Korrektur.",
    ),
    PromptSpec(
        "chat",
        "chat_system.txt",
        "RAG Chat",
        "System-Prompt für Fragen zu Paperless-Dokumenten.",
    ),
)

_PROMPTS_BY_KEY = {spec.key: spec for spec in PROMPT_SPECS}


def get_prompt_spec(key: str) -> PromptSpec:
    try:
        return _PROMPTS_BY_KEY[key]
    except KeyError as exc:
        raise KeyError(f"Unknown prompt key: {key}") from exc


def default_prompt_path(key: str) -> Path:
    return settings.prompts_dir / get_prompt_spec(key).filename


def override_prompt_path(key: str) -> Path:
    return Path(settings.data_dir) / get_prompt_spec(key).filename


def load_default_prompt(key: str) -> str:
    return default_prompt_path(key).read_text(encoding="utf-8")


def load_prompt(key: str) -> str:
    override = override_prompt_path(key)
    if override.is_file():
        return override.read_text(encoding="utf-8")
    return load_default_prompt(key)


def validate_prompt(content: str) -> str:
    normalized = content.replace("\r\n", "\n").replace("\r", "\n")
    if not normalized.strip():
        raise ValueError("Prompt must not be empty")
    if len(normalized) > MAX_PROMPT_CHARS:
        raise ValueError(f"Prompt is too large (max {MAX_PROMPT_CHARS} characters)")
    return normalized


def save_prompt(key: str, content: str) -> None:
    normalized = validate_prompt(content)
    path = override_prompt_path(key)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(normalized, encoding="utf-8")


def reset_prompt(key: str) -> None:
    path = override_prompt_path(key)
    if path.exists():
        path.unlink()


def prompt_payload() -> dict[str, object]:
    items: list[dict[str, object]] = []
    for spec in PROMPT_SPECS:
        default = load_default_prompt(spec.key)
        override = override_prompt_path(spec.key)
        content = override.read_text(encoding="utf-8") if override.is_file() else default
        items.append(
            {
                "key": spec.key,
                "label": spec.label,
                "description": spec.description,
                "filename": spec.filename,
                "content": content,
                "default_content": default,
                "overridden": override.is_file(),
                "chars": len(content),
            }
        )
    return {"items": items, "max_chars": MAX_PROMPT_CHARS}
