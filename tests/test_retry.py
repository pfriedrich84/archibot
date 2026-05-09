import pytest

from app.jobs.retry import RetryClass, classify_exception, retry_backoff_seconds, should_retry


@pytest.mark.parametrize(
    ("attempt", "expected"),
    [(1, 30), (2, 120), (3, 300), (4, 900), (5, 1800), (99, 1800)],
)
def test_retry_backoff_seconds_uses_bounded_default_schedule(attempt, expected):
    assert retry_backoff_seconds(attempt) == expected


def test_should_retry_stops_at_max_attempts():
    assert should_retry(RetryClass.TRANSIENT_NETWORK, attempt=4, max_attempts=5)
    assert not should_retry(RetryClass.TRANSIENT_NETWORK, attempt=5, max_attempts=5)


def test_should_retry_rejects_permanent_and_blocked_classes():
    assert not should_retry(RetryClass.PERMANENT_VALIDATION, attempt=1, max_attempts=5)
    assert not should_retry(RetryClass.PERMANENT_MISSING_DOCUMENT, attempt=1, max_attempts=5)
    assert not should_retry(RetryClass.BLOCKED_EMBEDDING_INDEX, attempt=1, max_attempts=5)
    assert not should_retry(RetryClass.CANCELLED, attempt=1, max_attempts=5)


def test_classify_exception_uses_http_status_when_available():
    exc = RuntimeError("rate limited")
    exc.status_code = 429

    assert classify_exception(exc) == RetryClass.RATE_LIMITED


def test_classify_exception_classifies_missing_document_status():
    exc = RuntimeError("missing")
    exc.status_code = 404

    assert classify_exception(exc) == RetryClass.PERMANENT_MISSING_DOCUMENT


def test_classify_exception_classifies_timeout_as_transient_network():
    assert classify_exception(TimeoutError("timed out")) == RetryClass.TRANSIENT_NETWORK


def test_classify_exception_classifies_value_error_as_recoverable_processing():
    assert (
        classify_exception(ValueError("model response could not be parsed"))
        == RetryClass.RECOVERABLE_PROCESSING
    )
