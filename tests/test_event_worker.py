import pytest

from app import event_worker


def test_recovery_loop_once_runs_only_transition_scan(monkeypatch):
    scans = []
    polls = []
    monkeypatch.setattr(event_worker, "run_recovery_scan", lambda limit: scans.append(limit))
    monkeypatch.setattr(
        event_worker, "enqueue_poll_reconciliation", lambda limit=None: polls.append(limit)
    )

    event_worker.run_recovery_loop(interval_seconds=1, once=True, limit=9)

    assert scans == [9]
    assert polls == []


def test_recovery_cli_routes_through_transition_facade(monkeypatch):
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


@pytest.mark.parametrize(
    ("function", "args"),
    [
        (event_worker.enqueue_poll_reconciliation, (5,)),
        (event_worker.start_queue_workers, ()),
    ],
)
def test_productive_absurd_entry_points_fail_closed(function, args):
    with pytest.raises(RuntimeError, match="Laravel database queues own"):
        function(*args)


def test_retired_cli_has_no_enqueue_or_worker_commands():
    parser = event_worker.build_parser()
    with pytest.raises(SystemExit):
        parser.parse_args(["enqueue-webhook", "--delivery-id", "42"])
    with pytest.raises(SystemExit):
        parser.parse_args(["start-workers"])
