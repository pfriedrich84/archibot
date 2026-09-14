"""JSON-safe contracts shared by Temporal workflows and activities."""

from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True)
class EmbeddingWorkflowRequest:
    command_id: int


@dataclass(frozen=True)
class PreparedEmbeddingBuild:
    command_id: int
    build_id: int
    document_ids: list[int]


@dataclass(frozen=True)
class EmbedDocumentRequest:
    build_id: int
    paperless_document_id: int


@dataclass(frozen=True)
class EmbedDocumentResult:
    paperless_document_id: int
    status: str


@dataclass(frozen=True)
class EmbeddingProgress:
    command_id: int
    build_id: int
    total: int
    done: int
    embedded: int
    failed: int


@dataclass(frozen=True)
class EmbeddingWorkflowResult:
    command_id: int
    build_id: int
    total: int
    embedded: int
    failed: int
    status: str


@dataclass(frozen=True)
class EmbeddingPreparationFailure:
    command_id: int
    error: str
