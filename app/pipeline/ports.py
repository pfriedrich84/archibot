"""Ports used by pipeline modules.

These Protocols describe the behaviour the Dokument-Verarbeitung needs without
coupling the deep pipeline module to concrete Paperless or AI-provider adapters.
"""

from __future__ import annotations

from typing import Any, Protocol

from app.models import PaperlessDocument, PaperlessEntity


class DocumentRepository(Protocol):
    """Paperless-facing port used by Dokument-Verarbeitung."""

    async def get_document(self, document_id: int) -> PaperlessDocument: ...

    async def patch_document(self, document_id: int, fields: dict[str, Any]) -> None: ...

    async def list_inbox_documents(self, inbox_tag_id: int) -> list[PaperlessDocument]: ...

    async def list_correspondents(self) -> list[PaperlessEntity]: ...

    async def list_document_types(self) -> list[PaperlessEntity]: ...

    async def list_storage_paths(self) -> list[PaperlessEntity]: ...

    async def list_tags(self) -> list[PaperlessEntity]: ...


class AiProviderGateway(Protocol):
    """AI-provider-facing port used by Dokument-Verarbeitung."""

    model: str
    embed_model: str
    ocr_model: str

    async def embed(self, text: str) -> list[float]: ...

    async def chat_json(
        self,
        *,
        system: str,
        user: str,
        model: str | None = None,
        num_ctx: int | None = None,
        role: str = "classification",
    ) -> dict[str, Any]: ...

    async def chat_vision_json(
        self,
        system: str,
        user: str,
        images: list[str],
        *,
        model: str | None = None,
        temperature: float = 0.1,
        num_ctx: int | None = None,
        role: str = "ocr",
    ) -> dict[str, Any]: ...

    async def chat(
        self,
        messages: list[dict[str, str]],
        *,
        model: str | None = None,
        temperature: float = 0.3,
    ) -> str: ...

    async def list_models(self) -> list[str]: ...

    async def model_available(self, name: str) -> bool: ...

    async def unload_model(self, model: str, *, swap: bool = False) -> None: ...

    async def aclose(self) -> None: ...


# Backward-compatible name for legacy call sites/tests while modules migrate.
LlmGateway = AiProviderGateway
