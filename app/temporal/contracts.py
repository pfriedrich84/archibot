"""JSON-safe contracts shared by Temporal workflows and activities."""

from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True)
class EmbeddingWorkflowRequest:
    command_id: int
    configuration: ModelPhaseConfiguration | None = None


@dataclass(frozen=True)
class PreparedEmbeddingBuild:
    command_id: int
    build_id: int
    document_ids: list[int]


@dataclass(frozen=True)
class EmbedDocumentRequest:
    build_id: int
    paperless_document_id: int
    configuration: ModelPhaseConfiguration | None = None


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


@dataclass(frozen=True)
class PollWorkflowRequest:
    command_id: int


@dataclass(frozen=True)
class DocumentWorkflowRequest:
    pipeline_run_id: int
    workflow_id: str = ""


@dataclass(frozen=True)
class DocumentWorkflowStart:
    pipeline_run_id: int
    workflow_id: str


@dataclass(frozen=True)
class PollDiscoveryResult:
    command_id: int
    documents_seen: int
    documents_skipped: int
    workflow_starts: list[DocumentWorkflowStart]
    status: str


@dataclass(frozen=True)
class PollWorkflowResult:
    command_id: int
    documents_seen: int
    documents_started: int
    documents_skipped: int
    status: str


@dataclass(frozen=True)
class DocumentReadiness:
    pipeline_run_id: int
    status: str
    review_suggestion_id: int | None = None


@dataclass(frozen=True)
class DocumentProcessResult:
    pipeline_run_id: int
    review_suggestion_id: int
    status: str


@dataclass(frozen=True)
class DocumentWorkflowResult:
    pipeline_run_id: int
    review_suggestion_id: int | None
    status: str


@dataclass(frozen=True)
class DocumentReviewCompletion:
    pipeline_run_id: int
    review_suggestion_id: int
    outcome: str


@dataclass(frozen=True)
class ModelPhaseConfiguration:
    phase: str
    provider_type: str
    provider_base_url: str
    model_id: str
    configuration_revision: str
    task_queue: str
    embedding_model: str
    classification_model: str
    ocr_text_model: str
    ocr_vision_model: str
    judge_model: str
    ocr_mode: str
    judge_enabled: bool
    judge_confidence_threshold: int
    embedding_num_ctx: int
    classification_num_ctx: int
    ocr_num_ctx: int
    ocr_requested_tag_id: int = 0


@dataclass(frozen=True)
class DocumentPhaseRequest:
    pipeline_run_id: int
    cycle: int
    configuration: ModelPhaseConfiguration


@dataclass(frozen=True)
class DocumentPhaseResult:
    pipeline_run_id: int
    phase: str
    status: str
    error: str | None = None


@dataclass(frozen=True)
class ReviewCommitRequest:
    review_suggestion_id: int
    command_id: int
    workflow_id: str


@dataclass(frozen=True)
class ReviewCommitResult:
    review_suggestion_id: int
    command_id: int
    status: str
    patched_fields: list[str]
