"""Retry classification contracts for event-driven actors."""

from __future__ import annotations

from enum import StrEnum


class RetryClass(StrEnum):
    TRANSIENT_NETWORK = "transient_network"
    TRANSIENT_PROVIDER = "transient_provider"
    TRANSIENT_PAPERLESS = "transient_paperless"
    RATE_LIMITED = "rate_limited"
    RECOVERABLE_PROCESSING = "recoverable_processing"
    PERMANENT_VALIDATION = "permanent_validation"
    PERMANENT_MISSING_DOCUMENT = "permanent_missing_document"
    BUG_UNEXPECTED = "bug_unexpected"
    BLOCKED_EMBEDDING_INDEX = "blocked_embedding_index"
    BLOCKED_DOCUMENT_LOCK = "blocked_document_lock"
    CANCELLED = "cancelled"


DEFAULT_BACKOFF_SECONDS = [30, 120, 300, 900, 1800]
RETRYABLE_CLASSES = frozenset(
    {
        RetryClass.TRANSIENT_NETWORK,
        RetryClass.TRANSIENT_PROVIDER,
        RetryClass.TRANSIENT_PAPERLESS,
        RetryClass.RATE_LIMITED,
        RetryClass.RECOVERABLE_PROCESSING,
        RetryClass.BUG_UNEXPECTED,
    }
)


def retry_backoff_seconds(attempt: int) -> int:
    """Return the bounded default backoff for a 1-based retry attempt."""
    index = max(0, attempt - 1)
    if index >= len(DEFAULT_BACKOFF_SECONDS):
        return DEFAULT_BACKOFF_SECONDS[-1]

    return DEFAULT_BACKOFF_SECONDS[index]


def should_retry(retry_class: RetryClass, *, attempt: int, max_attempts: int = 5) -> bool:
    """Return whether an actor should schedule another durable attempt."""
    return retry_class in RETRYABLE_CLASSES and attempt < max_attempts


def classify_exception(exc: BaseException) -> RetryClass:
    """Classify common actor exceptions without logging sensitive payloads.

    The classifier intentionally uses broad, dependency-light checks so it works
    with stdlib, httpx/aiohttp-style errors, Paperless client errors and LLM
    provider errors without importing optional transport libraries.
    """
    status_code = getattr(exc, "status_code", None) or getattr(exc, "status", None)
    if isinstance(status_code, int):
        if status_code == 429:
            return RetryClass.RATE_LIMITED
        if status_code == 404:
            return RetryClass.PERMANENT_MISSING_DOCUMENT
        if 400 <= status_code < 500:
            return RetryClass.PERMANENT_VALIDATION
        if status_code >= 500:
            return RetryClass.TRANSIENT_NETWORK

    if isinstance(exc, TimeoutError | ConnectionError):
        return RetryClass.TRANSIENT_NETWORK

    module_name = exc.__class__.__module__.lower()
    class_name = exc.__class__.__name__.lower()
    combined = f"{module_name}.{class_name}"
    if "paperless" in combined:
        return RetryClass.TRANSIENT_PAPERLESS
    if "ollama" in combined or "litellm" in combined or "provider" in combined:
        return RetryClass.TRANSIENT_PROVIDER
    if "timeout" in class_name:
        return RetryClass.TRANSIENT_NETWORK
    if "connection" in class_name or "network" in class_name:
        return RetryClass.TRANSIENT_NETWORK

    if isinstance(exc, ValueError):
        return RetryClass.RECOVERABLE_PROCESSING

    return RetryClass.BUG_UNEXPECTED
