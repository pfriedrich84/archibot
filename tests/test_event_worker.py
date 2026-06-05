from app import event_worker


def test_recovery_loop_once_runs_single_scan_and_poll_reconciliation(monkeypatch):
    scans = []
    polls = []
    sleeps = []

    monkeypatch.setattr(event_worker, "run_recovery_scan", lambda limit: scans.append(limit))
    monkeypatch.setattr(event_worker.settings, "poll_interval_seconds", 600)
    monkeypatch.setattr(
        event_worker, "enqueue_poll_reconciliation", lambda limit=None: polls.append(limit)
    )
    monkeypatch.setattr(event_worker.time, "sleep", lambda seconds: sleeps.append(seconds))

    event_worker.run_recovery_loop(interval_seconds=1, once=True, limit=9)

    assert scans == [9]
    assert polls == [None]
    assert sleeps == []


def test_enqueue_poll_reconciliation_uses_send_when_available(monkeypatch):
    sent = []

    class Actor:
        @staticmethod
        def send(limit):
            sent.append(limit)

    monkeypatch.setattr(event_worker, "reconcile_inbox_documents", Actor())

    event_worker.enqueue_poll_reconciliation(limit=5)

    assert sent == [5]


def test_main_enqueue_webhook_invokes_single_delivery_enqueue(monkeypatch):
    calls = []
    logs = []

    monkeypatch.setattr(
        event_worker,
        "enqueue_webhook_delivery",
        lambda webhook_delivery_id: calls.append(webhook_delivery_id),
    )
    monkeypatch.setattr(
        event_worker.log, "info", lambda *args, **kwargs: logs.append((args, kwargs))
    )

    assert event_worker.main(["enqueue-webhook", "--delivery-id", "42"]) == 0
    assert calls == [42]
    assert logs == [
        (("webhook delivery enqueue requested",), {"webhook_delivery_id": 42}),
    ]


def test_main_recovery_scan_once(monkeypatch):
    calls = []

    monkeypatch.setattr(
        event_worker,
        "run_recovery_loop",
        lambda **kwargs: calls.append(kwargs),
    )

    assert (
        event_worker.main(["recovery-scan", "--once", "--limit", "3", "--interval-seconds", "7"])
        == 0
    )
    assert calls == [{"interval_seconds": 7, "once": True, "limit": 3}]


def test_start_queue_workers_starts_absurd(monkeypatch):
    calls = []

    monkeypatch.setattr(
        event_worker,
        "has_queue_backend",
        lambda: True,
    )
    monkeypatch.setattr(
        event_worker,
        "start_queue_worker",
        lambda **kwargs: calls.append(kwargs),
    )

    event_worker.start_queue_workers(concurrency=3, claim_timeout=45)

    assert calls == [{"concurrency": 3, "claim_timeout": 45}]


def test_start_queue_workers_requires_queue_backend(monkeypatch):
    monkeypatch.setattr(event_worker, "has_queue_backend", lambda: False)

    try:
        event_worker.start_queue_workers()
    except RuntimeError as exc:
        assert "DATABASE_URL or ABSURD_DATABASE_URL" in str(exc)
    else:  # pragma: no cover
        raise AssertionError("expected RuntimeError")


def test_main_start_workers_invokes_queue_startup(monkeypatch):
    calls = []

    monkeypatch.setattr(event_worker, "has_queue_backend", lambda: True)
    monkeypatch.setattr(
        event_worker,
        "start_queue_workers",
        lambda **kwargs: calls.append(kwargs),
    )

    assert (
        event_worker.main(
            [
                "start-workers",
                "--concurrency",
                "4",
                "--claim-timeout",
                "99",
            ]
        )
        == 0
    )
    assert calls == [{"concurrency": 4, "claim_timeout": 99}]
