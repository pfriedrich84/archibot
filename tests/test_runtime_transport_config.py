import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_supervisor_uses_laravel_as_exclusive_queue_and_recovery_owner():
    supervisor = (ROOT / "docker" / "supervisord.conf").read_text(encoding="utf-8")

    assert "[program:laravel-queue-worker]" in supervisor
    assert "[program:laravel-scheduler]" in supervisor
    assert "[program:laravel-durable-recovery]" in supervisor
    assert "php artisan schedule:work" in supervisor
    assert "php artisan archibot:recovery-scan" in supervisor
    assert "python -m app.event_worker" not in supervisor
    assert "start-workers" not in supervisor
    assert "[program:event-queue-workers]" not in supervisor
    assert "[program:event-recovery-bridge]" not in supervisor


def test_fixed_actor_modules_import_when_retired_sdk_is_unavailable():
    blocker = """
import builtins
real_import = builtins.__import__
def guarded(name, *args, **kwargs):
    if name == 'absurd_sdk' or name.startswith('absurd_sdk.'):
        raise ModuleNotFoundError(name)
    return real_import(name, *args, **kwargs)
builtins.__import__ = guarded
from app import actor_runner
for module in (
    'app.actors.document', 'app.actors.embedding', 'app.actors.maintenance',
    'app.actors.review', 'app.actors.webhook'
):
    __import__(module)
assert actor_runner.build_parser()
"""
    result = subprocess.run(
        [sys.executable, "-c", blocker],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=False,
    )

    assert result.returncode == 0, result.stderr


def test_runtime_manifests_have_only_laravel_database_queue_transport():
    compose = (ROOT / "docker-compose.yml").read_text(encoding="utf-8")
    env_example = (ROOT / ".env.example").read_text(encoding="utf-8")
    dockerfile = (ROOT / "Dockerfile").read_text(encoding="utf-8")
    pyproject = (ROOT / "pyproject.toml").read_text(encoding="utf-8")
    migrations = "\n".join(
        path.read_text(encoding="utf-8")
        for path in (ROOT / "laravel" / "database" / "migrations").glob("*.php")
    )

    forbidden = ("ABSURD_DATABASE_URL", "ARCHIBOT_QUEUE_PREFIX", "absurd-sdk")
    for source in (compose, env_example, dockerfile, pyproject, migrations):
        assert all(token not in source for token in forbidden)
    assert "QUEUE_CONNECTION=database" in env_example
    assert "QUEUE_CONNECTION: ${QUEUE_CONNECTION:-database}" in compose
