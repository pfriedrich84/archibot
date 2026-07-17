from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_supervisor_uses_laravel_as_exclusive_queue_and_recovery_owner():
    supervisor = (ROOT / "docker" / "supervisord.conf").read_text(encoding="utf-8")

    assert "[program:laravel-queue-worker]" in supervisor
    assert "[program:laravel-scheduler]" in supervisor
    assert "[program:laravel-durable-recovery]" in supervisor
    assert "php artisan schedule:work" in supervisor
    assert "php artisan archibot:recovery-scan" in supervisor
    assert "app.event_worker" not in supervisor
    assert "[program:event-queue-workers]" not in supervisor
    assert "[program:event-recovery-bridge]" not in supervisor
