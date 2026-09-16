"""Activities that freeze model configuration and inspect embedding readiness."""

from __future__ import annotations

import asyncio
import hashlib
import json

from temporalio import activity

from app.config import settings
from app.jobs.commands import sql_text
from app.jobs.database import engine
from app.temporal.contracts import ModelPhaseConfiguration
from app.temporal.names import (
    CLASSIFICATION_TASK_QUEUE,
    EMBEDDING_TASK_QUEUE,
    JUDGE_TASK_QUEUE,
    OCR_TEXT_TASK_QUEUE,
    OCR_VISION_TASK_QUEUE,
)

_MODEL_PHASES = {"embedding", "ocr", "classification", "judge"}


def _configuration_payload() -> dict[str, object]:
    return {
        "provider_type": settings.llm_provider,
        "provider_base_url": settings.ollama_url,
        "embedding_model": settings.ollama_embed_model,
        "classification_model": settings.ollama_model,
        "ocr_text_model": settings.ollama_ocr_model,
        "ocr_vision_model": settings.ocr_vision_model,
        "judge_model": settings.ollama_judge_model or settings.ollama_model,
        "ocr_mode": settings.ocr_mode,
        "ocr_requested_tag_id": settings.ocr_requested_tag_id,
        "judge_enabled": settings.enable_judge_verification,
        "judge_confidence_threshold": settings.judge_confidence_threshold,
        "embedding_num_ctx": settings.ollama_embed_num_ctx,
        "classification_num_ctx": settings.ollama_num_ctx,
        "ocr_num_ctx": settings.ollama_ocr_num_ctx,
    }


def _load_model_phase_configuration(phase: str) -> ModelPhaseConfiguration:
    if phase not in _MODEL_PHASES:
        raise ValueError(f"Unknown model phase: {phase}")
    payload = _configuration_payload()
    revision = hashlib.sha256(
        json.dumps(payload, sort_keys=True, separators=(",", ":")).encode()
    ).hexdigest()
    if phase == "embedding":
        model_id = str(payload["embedding_model"])
        task_queue = EMBEDDING_TASK_QUEUE
    elif phase == "classification":
        model_id = str(payload["classification_model"])
        task_queue = CLASSIFICATION_TASK_QUEUE
    elif phase == "judge":
        model_id = str(payload["judge_model"])
        task_queue = JUDGE_TASK_QUEUE
    else:
        mode = str(payload["ocr_mode"])
        model_id = (
            str(payload["ocr_vision_model"])
            if mode in {"vision_light", "vision_full"}
            else str(payload["ocr_text_model"])
        )
        task_queue = (
            OCR_VISION_TASK_QUEUE
            if mode in {"vision_light", "vision_full"}
            else OCR_TEXT_TASK_QUEUE
        )
    return ModelPhaseConfiguration(
        phase=phase,
        provider_type=str(payload["provider_type"]),
        provider_base_url=str(payload["provider_base_url"]),
        model_id=model_id,
        configuration_revision=revision,
        task_queue=task_queue,
        embedding_model=str(payload["embedding_model"]),
        classification_model=str(payload["classification_model"]),
        ocr_text_model=str(payload["ocr_text_model"]),
        ocr_vision_model=str(payload["ocr_vision_model"]),
        judge_model=str(payload["judge_model"]),
        ocr_mode=str(payload["ocr_mode"]),
        judge_enabled=bool(payload["judge_enabled"]),
        judge_confidence_threshold=int(payload["judge_confidence_threshold"]),
        embedding_num_ctx=int(payload["embedding_num_ctx"]),
        classification_num_ctx=int(payload["classification_num_ctx"]),
        ocr_num_ctx=int(payload["ocr_num_ctx"]),
        ocr_requested_tag_id=int(payload["ocr_requested_tag_id"]),
    )


@activity.defn(name="archibot.load_model_phase_configuration")
async def load_model_phase_configuration(phase: str) -> ModelPhaseConfiguration:
    """Freeze the public provider/model configuration for one phase activation."""
    return _load_model_phase_configuration(phase)


def _embedding_index_status(embedding_model: str) -> str:
    with engine().connect() as connection:
        row = (
            connection.execute(
                sql_text(
                    """
                    SELECT status
                    FROM embedding_index_state
                    WHERE embedding_model = :embedding_model
                    ORDER BY updated_at DESC, id DESC
                    LIMIT 1
                    """
                ),
                {"embedding_model": embedding_model},
            )
            .mappings()
            .first()
        )
    return "missing" if row is None else str(row["status"])


@activity.defn(name="archibot.embedding_index_status")
async def embedding_index_status(embedding_model: str) -> str:
    """Return readiness for the embedding model pinned to this phase."""
    return await asyncio.to_thread(_embedding_index_status, embedding_model)
